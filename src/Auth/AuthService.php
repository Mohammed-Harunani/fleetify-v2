<?php
declare(strict_types=1);

namespace Fleetify\Auth;

use Fleetify\Core\Session;
use Fleetify\Repositories\UserRepository;

final class AuthService
{
    private UserRepository $users;
    private LoginRateLimiter $rateLimiter;
    private IpRateLimiter $ipRateLimiter;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->rateLimiter = new LoginRateLimiter();
        $this->ipRateLimiter = new IpRateLimiter();
    }

    public function login(
        int $companyId,
        string $email,
        string $password,
        ?string $clientIp = null
    ): bool {
        $clientIp ??= $_SERVER['REMOTE_ADDR'] ?? null;

        if (
            $clientIp === null ||
            filter_var($clientIp, FILTER_VALIDATE_IP) === false
        ) {
            return false;
        }

        if ($this->ipRateLimiter->isLocked($clientIp)) {
            return false;
        }
        if (
            $companyId <= 0 ||
            trim($email) === '' ||
            $password === ''
        ) {
            $this->ipRateLimiter->recordFailure($clientIp);
            return false;
        }

        $email = strtolower(trim($email));

        // Validate the tenant before recording login attempts.
        $statement = \Fleetify\Core\Database::connection()->prepare(
            "SELECT id
             FROM companies
             WHERE id = :company_id
               AND status = 'active'
             LIMIT 1"
        );

        $statement->execute([
            'company_id' => $companyId,
        ]);

        if ($statement->fetchColumn() === false) {
            $this->ipRateLimiter->recordFailure($clientIp);
            return false;
        }

        // Reject attempts during an active lockout.
        if ($this->rateLimiter->isLocked($companyId, $email)) {
            $this->ipRateLimiter->recordFailure($clientIp);
            return false;
        }

        $user = $this->users->findByEmail(
            $companyId,
            $email
        );

        $authenticated = (
            $user !== null
            && $user['status'] === 'active'
            && password_verify(
                $password,
                $user['password_hash']
            )
        );

        if (!$authenticated) {
            $this->ipRateLimiter->recordFailure($clientIp);

            $this->rateLimiter->recordFailure(
                $companyId,
                $email
            );

            return false;
        }

        // Reset the failed-attempt counter after success.
        $this->rateLimiter->clearFailures(
            $companyId,
            $email
        );

        Session::start();
        Session::regenerate();

        // Clear any previous authentication context.
        unset(
            $_SESSION['staff_auth'],
            $_SESSION['client_auth']
        );

        // Store only the authenticated staff identity.
        $_SESSION['staff_auth'] = [
            'user_id' => (int) $user['id'],
            'company_id' => (int) $user['company_id'],
        ];

        // Initialise staff session lifetime tracking.
        $_SESSION['staff_authenticated_at'] = time();
        $_SESSION['staff_last_activity'] = time();

        // Rotate the CSRF token after authentication.
        unset($_SESSION['csrf_token']);

        return true;
    }

    public function logout(): void
    {
        Session::destroy();
    }

    public function staffIdentity(): ?array
    {
        $identity = Session::get('staff_auth');

        if (
            !is_array($identity) ||
            !isset(
                $identity['user_id'],
                $identity['company_id']
            )
        ) {
            return null;
        }

        // Enforce staff session idle and absolute timeouts.
        $now = time();

        $authenticatedAt = filter_var(
            $_SESSION['staff_authenticated_at'] ?? null,
            FILTER_VALIDATE_INT
        );

        $lastActivity = filter_var(
            $_SESSION['staff_last_activity'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $authenticatedAt === false ||
            $lastActivity === false ||
            $authenticatedAt === null ||
            $lastActivity === null ||
            $authenticatedAt <= 0 ||
            $lastActivity <= 0 ||
            $authenticatedAt > $now ||
            $lastActivity > $now ||
            $lastActivity < $authenticatedAt ||
            ($now - $authenticatedAt) >= 28800 ||
            ($now - $lastActivity) >= 1800
        ) {
            Session::destroy();
            return null;
        }

        // Refresh activity only after a valid session check.
        $_SESSION['staff_last_activity'] = $now;

        $userId = filter_var(
            $identity['user_id'],
            FILTER_VALIDATE_INT
        );

        $companyId = filter_var(
            $identity['company_id'],
            FILTER_VALIDATE_INT
        );

        if (
            $userId === false ||
            $companyId === false ||
            $userId <= 0 ||
            $companyId <= 0
        ) {
            return null;
        }

        $user = $this->users->findById(
            $companyId,
            $userId
        );

        if (
            $user === null ||
            $user['status'] !== 'active'
        ) {
            return null;
        }

        return $user;
    }
}

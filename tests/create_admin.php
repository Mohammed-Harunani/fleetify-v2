<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Core\Database;

if (PHP_SAPI !== 'cli') {
    exit("ERROR: CLI execution only.\n");
}

$companyId = 1;
$name = 'Fleetify Test Administrator';
$email = 'admin@fleetify.test';

$password = getenv('FLEETIFY_SETUP_PASSWORD');

if (!is_string($password) || strlen($password) < 12) {
    exit("ERROR: Password was not received or is too short.\n");
}

try {
    $pdo = Database::connection();

    $check = $pdo->prepare(
        'SELECT id FROM users
         WHERE company_id = :company_id
           AND email = :email
         LIMIT 1'
    );

    $check->execute([
        'company_id' => $companyId,
        'email' => $email,
    ]);

    if ($check->fetch()) {
        exit("ERROR: Administrator already exists.\n");
    }

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    unset($password);

    $statement = $pdo->prepare(
        'INSERT INTO users
            (company_id, office_id, name, email,
             password_hash, role, status)
         VALUES
            (:company_id, NULL, :name, :email,
             :password_hash, :role, :status)'
    );

    $statement->execute([
        'company_id' => $companyId,
        'name' => $name,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => 'admin',
        'status' => 'active',
    ]);

    echo "ADMINISTRATOR CREATED: SUCCESS\n";
    echo "COMPANY ID: {$companyId}\n";
    echo "EMAIL: {$email}\n";
    echo "USER ID: " . $pdo->lastInsertId() . "\n";

} catch (Throwable $exception) {
    error_log(
        '[Fleetify Admin Setup] ' . $exception->getMessage()
    );

    fwrite(STDERR, "ERROR: Administrator setup failed.\n");
    exit(1);
}

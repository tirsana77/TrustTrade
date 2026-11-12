<?php

// ------------------------------------------------------------
// DATABASE CONFIGURATION
// ------------------------------------------------------------
//
// getenv() reads environment variables supplied by Docker
// Compose.
//
// The values after ?: are fallback defaults in case the
// environment variables are not available.

$host = getenv('DB_HOST') ?: 'db';
$dbname = getenv('DB_NAME') ?: 'studentdb';
$username = getenv('DB_USER') ?: 'appuser';
$password = getenv('DB_PASSWORD') ?: 'apppassword';

$error = null;
$messages = [];

try {

    // Create a PDO connection to the MySQL database.
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    // Tell PDO to throw exceptions when database errors occur.
    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    // --------------------------------------------------------
    // HANDLE FORM SUBMISSION
    // --------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $name = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($name !== '' && $message !== '') {

            // Use a prepared statement rather than placing
            // user input directly into the SQL query.
            $stmt = $pdo->prepare(
                "INSERT INTO messages (name, message)
                 VALUES (:name, :message)"
            );

            $stmt->execute([
                ':name' => $name,
                ':message' => $message
            ]);

            // Redirect after submission to avoid duplicate
            // form submission if the page is refreshed.
            header("Location: /");
            exit;
        }
    }

    // --------------------------------------------------------
    // READ MESSAGES FROM DATABASE
    // --------------------------------------------------------
    $stmt = $pdo->query(
        "SELECT id, name, message, created_at
         FROM messages
         ORDER BY created_at DESC"
    );

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $error = $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Student Message Board</title>

    <link rel="stylesheet" href="style.css">
</head>

<body>

<div class="container">

    <h1>Student Message Board</h1>

    <p>
        A simple PHP + MySQL full-stack application running
        with Docker Compose.
    </p>

    <?php if ($error): ?>

        <div class="error">

            <strong>Database connection error:</strong>

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>

    <form method="POST">

        <label for="name">
            Your Name
        </label>

        <input
            type="text"
            id="name"
            name="name"
            required
        >

        <label for="message">
            Message
        </label>

        <textarea
            id="message"
            name="message"
            required
        ></textarea>

        <button type="submit">
            Add Message
        </button>

    </form>

    <h2>Messages</h2>

    <?php if (empty($messages)): ?>

        <p>No messages yet.</p>

    <?php else: ?>

        <?php foreach ($messages as $row): ?>

            <div class="message">

                <h3>
                    <?= htmlspecialchars($row['name']) ?>
                </h3>

                <p>
                    <?= htmlspecialchars($row['message']) ?>
                </p>

                <small>
                    <?= htmlspecialchars($row['created_at']) ?>
                </small>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>

</html>

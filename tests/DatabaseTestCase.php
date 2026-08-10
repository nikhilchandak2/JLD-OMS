<?php

namespace Tests;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that hit the database. Each test runs inside one transaction that is
 * rolled back afterwards, and every fixture it needs is created by the test itself so the
 * suite does not depend on seed data or on ids left behind by earlier tests.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        $this->database = new Database();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $this->database->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->database->rollback();
        $_SESSION = [];
    }

    protected function uniqueSuffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    protected function createCompany(?string $orderPrefix = null): int
    {
        $suffix = $this->uniqueSuffix();
        $name = "Test Company {$suffix}";
        $code = "TST{$suffix}";
        $prefix = $orderPrefix ?? ('T' . strtoupper(substr($suffix, 0, 3)));

        // order_prefix column added in migration 044
        try {
            $this->database->execute(
                "INSERT INTO companies (name, code, order_prefix, status) VALUES (?, ?, ?, 'active')",
                [$name, $code, $prefix]
            );
        } catch (\Throwable $e) {
            $this->database->execute(
                "INSERT INTO companies (name, code, status) VALUES (?, ?, 'active')",
                [$name, $code]
            );
        }

        return (int)$this->database->lastInsertId();
    }

    protected function createParty(?float $creditLimit = null): int
    {
        $suffix = $this->uniqueSuffix();
        $this->database->execute(
            "INSERT INTO parties (name, contact_person, phone, email, address, is_active, credit_limit)
             VALUES (?, 'Test Contact', '9999999999', ?, 'Test address', 1, ?)",
            ["Test Party {$suffix}", "party{$suffix}@example.test", $creditLimit]
        );

        return (int)$this->database->lastInsertId();
    }

    protected function createProduct(): int
    {
        $suffix = $this->uniqueSuffix();
        $this->database->execute(
            "INSERT INTO products (code, name, is_active) VALUES (?, ?, 1)",
            ["TST-{$suffix}", "Test Product {$suffix}"]
        );

        return (int)$this->database->lastInsertId();
    }

    protected function createUser(string $role = 'admin', string $password = 'Test@Passw0rd!', bool $isActive = true): array
    {
        $suffix = $this->uniqueSuffix();
        $email = "user{$suffix}@jldminerals.com";
        $roleId = $this->roleId($role);

        $this->database->execute(
            "INSERT INTO users (email, password_hash, name, role_id, is_active) VALUES (?, ?, ?, ?, ?)",
            [$email, password_hash($password, PASSWORD_DEFAULT), "Test User {$suffix}", $roleId, $isActive ? 1 : 0]
        );

        return [
            'id' => (int)$this->database->lastInsertId(),
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ];
    }

    protected function roleId(string $role): int
    {
        $row = $this->database->fetch("SELECT id FROM roles WHERE name = ?", [$role]);
        if (!$row) {
            $this->database->execute("INSERT INTO roles (name) VALUES (?)", [$role]);
            return (int)$this->database->lastInsertId();
        }

        return (int)$row['id'];
    }
}

<?php

namespace App\Repositories;

use App\Core\Database;

class TripStoppageRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function insert(int $tripId, string $startTime, string $endTime, float $durationMinutes, ?float $latitude = null, ?float $longitude = null): int
    {
        $sql = "
            INSERT INTO trip_stoppages (trip_id, start_time, end_time, duration_minutes, latitude, longitude)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $this->database->execute($sql, [$tripId, $startTime, $endTime, $durationMinutes, $latitude, $longitude]);
        return (int)$this->database->lastInsertId();
    }

    /**
     * @return array[] List of stoppage rows for a trip
     */
    public function getByTripId(int $tripId): array
    {
        $sql = "
            SELECT * FROM trip_stoppages
            WHERE trip_id = ?
            ORDER BY start_time ASC
        ";
        return $this->database->fetchAll($sql, [$tripId]);
    }
}

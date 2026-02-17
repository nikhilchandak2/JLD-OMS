<?php

namespace App\Services;

use App\Core\Database;

class FuelAlertService
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    /**
     * Check for fuel alerts based on new reading
     */
    public function checkFuelAlerts(int $vehicleId, $fuelReading): void
    {
        $fuelLevel = $fuelReading->fuelLevel;
        $fuelPercentage = $fuelReading->fuelPercentage;
        
        // Low fuel alert (below 20%)
        if ($fuelPercentage !== null && $fuelPercentage < 20) {
            $this->createAlert($vehicleId, 'low_fuel', $fuelLevel, $fuelPercentage, 
                "Low fuel warning: {$fuelPercentage}% remaining");
        }
        
        // Check for rapid consumption (fuel theft or leak)
        $this->checkRapidConsumption($vehicleId, $fuelReading);
        
        // Check for sensor fault (sudden jumps)
        $this->checkSensorFault($vehicleId, $fuelReading);
    }
    
    /**
     * Check for rapid fuel consumption (possible theft)
     */
    private function checkRapidConsumption(int $vehicleId, $fuelReading): void
    {
        // Get previous reading (within last 5 minutes)
        $sql = "
            SELECT fuel_level, fuel_percentage, timestamp
            FROM fuel_reading_data
            WHERE vehicle_id = ? 
            AND timestamp < ?
            AND timestamp >= DATE_SUB(?, INTERVAL 5 MINUTE)
            ORDER BY timestamp DESC
            LIMIT 1
        ";
        
        $previous = $this->database->fetch($sql, [
            $vehicleId,
            $fuelReading->timestamp,
            $fuelReading->timestamp
        ]);
        
        if (!$previous) {
            return;
        }
        
        $consumed = $previous['fuel_level'] - $fuelReading->fuelLevel;
        
        // Alert if more than 10 liters consumed in 5 minutes (and vehicle is stationary)
        if ($consumed > 10) {
            $this->createAlert($vehicleId, 'fuel_theft', $fuelReading->fuelLevel, 
                $fuelReading->fuelPercentage,
                "Rapid fuel consumption detected: {$consumed}L in 5 minutes");
        }
    }
    
    /**
     * Check for sensor fault (sudden jumps in fuel level)
     */
    private function checkSensorFault(int $vehicleId, $fuelReading): void
    {
        // Get previous reading
        $sql = "
            SELECT fuel_level, fuel_percentage
            FROM fuel_reading_data
            WHERE vehicle_id = ? 
            AND timestamp < ?
            ORDER BY timestamp DESC
            LIMIT 1
        ";
        
        $previous = $this->database->fetch($sql, [
            $vehicleId,
            $fuelReading->timestamp
        ]);
        
        if (!$previous) {
            return;
        }
        
        $jump = abs($previous['fuel_level'] - $fuelReading->fuelLevel);
        
        // Alert if fuel level jumped more than 50% of tank capacity
        if ($jump > 50) {
            $this->createAlert($vehicleId, 'sensor_fault', $fuelReading->fuelLevel,
                $fuelReading->fuelPercentage,
                "Sensor fault detected: Fuel level jumped {$jump}L");
        }
    }
    
    /**
     * Create a fuel alert
     */
    private function createAlert(int $vehicleId, string $alertType, ?float $fuelLevel, 
                                ?float $fuelPercentage, string $message): void
    {
        // Check if similar alert already exists (not resolved)
        $sql = "
            SELECT id FROM fuel_alerts
            WHERE vehicle_id = ? 
            AND alert_type = ?
            AND is_resolved = 0
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";
        
        $existing = $this->database->fetch($sql, [$vehicleId, $alertType]);
        
        if ($existing) {
            return; // Don't create duplicate alerts
        }
        
        // Create new alert
        $sql = "
            INSERT INTO fuel_alerts 
            (vehicle_id, alert_type, fuel_level, fuel_percentage, message)
            VALUES (?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $vehicleId,
            $alertType,
            $fuelLevel,
            $fuelPercentage,
            $message
        ]);
    }
    
    /**
     * Get unresolved alerts for vehicle
     */
    public function getUnresolvedAlerts(int $vehicleId): array
    {
        $sql = "
            SELECT * FROM fuel_alerts
            WHERE vehicle_id = ? AND is_resolved = 0
            ORDER BY created_at DESC
        ";
        
        return $this->database->fetchAll($sql, [$vehicleId]);
    }
    
    /**
     * Resolve an alert
     */
    public function resolveAlert(int $alertId): bool
    {
        $sql = "
            UPDATE fuel_alerts 
            SET is_resolved = 1, resolved_at = NOW()
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [$alertId]);
    }
}

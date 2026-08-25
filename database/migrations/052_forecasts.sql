-- Monthly grade-level forecasting (TASK 7).
-- Prefill from last 3 completed months of dispatched tonnage.
-- forecast_actuals is a nightly read model. forecast_gap_flag on dormancy is populated from this.
-- Rollback: database/rollback/052_forecasts.down.sql

CREATE TABLE IF NOT EXISTS forecast_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NULL,
  period_month CHAR(7) NOT NULL,
  status ENUM('open','locked','closed') NOT NULL DEFAULT 'open',
  opened_at DATETIME NOT NULL,
  locked_at DATETIME NULL,
  opened_by_user_id INT NULL,
  locked_by_user_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_forecast_period_month (period_month),
  INDEX idx_forecast_periods_status (status),
  CONSTRAINT fk_forecast_periods_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT fk_forecast_periods_opened_by FOREIGN KEY (opened_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_forecast_periods_locked_by FOREIGN KEY (locked_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS forecast_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_id INT NOT NULL,
  party_id INT NOT NULL,
  owner_user_id INT NULL,
  grade_code VARCHAR(64) NOT NULL,
  qty_low_tonnes DECIMAL(10,2) NOT NULL,
  qty_high_tonnes DECIMAL(10,2) NOT NULL,
  source ENUM('prefilled','edited','added') NOT NULL DEFAULT 'prefilled',
  confidence ENUM('high','medium','low') NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_forecast_line (period_id, party_id, grade_code),
  INDEX idx_forecast_lines_owner (period_id, owner_user_id),
  INDEX idx_forecast_lines_party (party_id, period_id),
  CONSTRAINT fk_forecast_lines_period FOREIGN KEY (period_id) REFERENCES forecast_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_forecast_lines_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_forecast_lines_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_forecast_qty_range CHECK (
    qty_low_tonnes >= 0 AND qty_high_tonnes >= 0 AND qty_low_tonnes <= qty_high_tonnes
  )
);

CREATE TABLE IF NOT EXISTS forecast_actuals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_id INT NOT NULL,
  party_id INT NOT NULL,
  grade_code VARCHAR(64) NOT NULL,
  forecast_low DECIMAL(10,2) NOT NULL,
  forecast_high DECIMAL(10,2) NOT NULL,
  actual_tonnes DECIMAL(12,3) NOT NULL DEFAULT 0,
  variance_vs_midpoint DECIMAL(10,2) NOT NULL DEFAULT 0,
  as_of DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_forecast_actual (period_id, party_id, grade_code),
  INDEX idx_forecast_actuals_grade (period_id, grade_code),
  INDEX idx_forecast_actuals_as_of (as_of),
  CONSTRAINT fk_forecast_actuals_period FOREIGN KEY (period_id) REFERENCES forecast_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_forecast_actuals_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE
);

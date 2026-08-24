-- Rollback 047_data_feeds. Drops live + staging + config in FK-safe order.

DROP TABLE IF EXISTS data_feed_locks;
DROP TABLE IF EXISTS dispatch_day_entries;
DROP TABLE IF EXISTS ledger_outstanding;
DROP TABLE IF EXISTS party_source_aliases;
DROP TABLE IF EXISTS data_feed_rows;
DROP TABLE IF EXISTS data_feed_runs;
DROP TABLE IF EXISTS data_feeds;

# Maintenance and analytics

Administrators create scheduled work under Maintenance Support. Select a barangay, optionally one of its assigned NAP boxes, a technician, priority and due date. An optional interruption link must belong to the same barangay. Work moves from Scheduled to In progress to Completed. Completion requires findings/work performed; materials can be recorded as well. Overdue means unfinished and past its due time. All maintenance dates use Asia/Manila time. These records are stored separately from legacy support tickets.

Technicians access their own orders through Scheduled maintenance in the technician sidebar. They can start and complete only their assigned orders. Administrative access can update all orders. Completing an order does not resolve a linked interruption or send an SMS; subscriber confirmation remains in the existing interruption workflow.

Analytics filters by interruption report creation date, inclusive of both selected dates, with a maximum range of 366 days. Repair time measures report creation to technician completion. Acceptance time measures report creation to the first recorded accept event. Records without usable timestamps are excluded from averages; no samples displays No data. Repeat connections have more than one report in the selected range. Affected connections are counted once, even with repeat reports.

Barangay and NAP names reflect current subscriber assignments, not historical assignment snapshots. NAP capacity and subscriber status are current snapshots and are not restricted by the date filter. Capacity includes assigned subscribers plus pending applications. Near capacity starts at 80%. Subscriber status is based on saved records and reports; uptime is not calculated without continuous monitoring.

The analytics CSV exports report rows, summary metrics and the current capacity snapshot. The legacy support-ticket report respects its priority/status filters. Maintenance ticket charts count actual ticket records rather than stored monthly demonstration metrics.

The new work-order table is created automatically on first use. No reinstall is needed. Validation: `C:/xampp/php/php.exe tests/maintenance.php` uses its own temporary local database and removes it on completion.

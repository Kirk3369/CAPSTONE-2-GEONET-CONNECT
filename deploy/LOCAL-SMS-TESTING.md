# Local SMS inbox testing

1. Start Apache and MySQL in XAMPP.
2. Sign in as an administrator at http://localhost/geonet_connect/ .
3. Open Settings > Open SMS inbox and local simulator, or visit
   http://localhost/geonet_connect/sms-inbox.php .
4. Use an existing test connection's public ID and its registered phone number.
   The page creates the additive sms_inbox table on first use. No SQL import or
   installer rebuild is needed.
5. Submit DOWN followed by the connection ID. Check the inbox result, then refresh
   the dashboard/map and confirm only that connection is offline.
6. Submit the same message with the same message ID to test duplicate delivery.
   The inbox should contain one entry and the notification should not repeat.
7. Choose New message ID before changing the sender or message. Submit RESTORED
   followed by the connection ID and confirm the connection returns online.
8. Test an unregistered phone, a connection belonging to a different phone, an
   unknown connection, and an invalid command. Each should be rejected and logged
   without changing connection status. Malformed phone numbers are rejected before
   insertion. Reusing an ID with different message content produces an error.

Supported commands are case-insensitive and require the connection ID. Local
09..., 9..., and international +639... Philippine mobile formats are normalized.
Multiple connections may share a phone; the command affects only its specified ID.
An already-offline DOWN or already-online RESTORED is recorded as unchanged.

This is a simulation: the administrator supplies the sender number. No real SMS
is received or sent. The API requires an authenticated administrator and CSRF
token; simulation POST requests are allowed only from localhost and disabled on
Render. Real inbound gateway authentication, acknowledgment SMS, and suspected
area outage grouping are later integration steps.

## Technician assignment and restoration confirmation

DOWN now creates one active interruption report for the connection, including
when the connection was already offline. If you tested DOWN before this feature
was added, send it again with a new message ID to create the report.

1. As admin, open Interruption reports from the dashboard or SMS inbox and assign
   a technician who has an active sign-in account.
   Reports also appear in Network Activity. Click an activity entry to focus the
   subscriber's map pin and open its report popup, or click the offline subscriber
   marker directly. Assign or reassign a technician from that popup. Reports
   awaiting subscriber confirmation show their deadline without assignment controls.
   Network Activity also provides a technician selector and Assign/Reassign button
   directly on eligible interruption and subscription-request updates. Assignment
   remains on the dashboard; completed or resolved work has no assignment shortcut.
2. As that technician, open Interruption repairs, accept, mark on route, then mark
   repair complete. Declining or reporting an incomplete repair requires a reason
   and returns the report to the administrator for reassignment.
3. Completion starts a five-hour countdown using the database clock. The
   subscriber remains offline. Admins and technicians cannot confirm restoration.
4. As the owning subscriber, open My interruption reports and choose My connection
   is restored or Still no connection. Registered-sender RESTORED and DOWN SMS
   simulations provide the same responses. Still down cancels the countdown and
   returns the repair to its assigned technician. A new completion restarts five
   hours. Confirmation closes the report and marks the connection online.
5. Without a subscriber response, the worker closes the report after the deadline
   and records automatic confirmation distinctly from subscriber confirmation.
   Other subscribers and connections are not changed.

## Local background worker

From PowerShell in the project directory, run:

```powershell
& C:\xampp\php\php.exe scripts\interruption-worker.php --watch
```

Keep this process and MySQL running for unattended expiry. It checks every 30
seconds, so automatic confirmation normally happens within 30 seconds after the
five-hour deadline. It does not require a browser to remain open. A process must
be started again after a computer restart. The reports page displays worker health.
Opening or refreshing reports also processes overdue confirmations as a fallback.

For a single sweep, omit `--watch`. Do not shorten real report deadlines to test;
the integration test sets expired deadlines only inside its temporary database.
To stop a background watcher safely, create `runtime/interruption-worker.stop`.
Remove that stop file before starting another watcher. Runtime files are ignored
by Git and Docker. The watcher allows only one instance per checkout.

The worker is local for now. Before deploying, configure an always-running worker
or scheduler on the hosting platform; a free web service alone cannot guarantee
unattended deadlines. Keep using local XAMPP until verification is complete.

Integration checks: run `C:\xampp\php\php.exe tests\sms-inbox.php` from the project
root. The script refuses nonlocal database hosts, creates a temporary database,
uses strict SQL mode, and removes only its generated test database afterward.
It requires a local MySQL account allowed to create databases. Application data
is not used as test fixtures.

Repair workflow checks: `C:\xampp\php\php.exe tests\interruptions.php`. This also
uses a separate temporary database, including simulated expired deadlines.

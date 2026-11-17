# SMSGate setup

1. Start Cloud Server in the Android app. Allow sending and receiving SMS, select the intended SIM, and keep the phone online with an SMS allowance or load.
2. In GeoNet administrator Settings, open SMS API (SMSGate). Enter the cloud username and password from the Android app. Optionally enter the device ID shown in the SMSGate dashboard to target one phone.
3. Keep mock mode enabled while checking recipient previews. Disable it and save when ready for live sends. Existing Semaphore credentials are not reused; migration defaults to mock mode until SMSGate settings are saved.
4. Send from an active outage's SMS composer. GeoNet records cloud acceptance as queued, not delivered. Use the SMSGate dashboard for the final delivery status. For timeout or 502 errors, check the dashboard before retrying to avoid duplicate texts.

## Incoming subscriber reports

1. In the Android app, copy Settings > Webhooks > Signing Key into GeoNet's webhook signing key field and save. Blank password/key fields preserve saved secrets.
2. Give the GeoNet installation a reachable HTTPS address with a trusted certificate (public hosting or a development tunnel). Never use the computer's localhost address as the webhook URL.
3. In the SMSGate dashboard's Webhooks page, register the event `sms:received` with URL `https://YOUR-HOST/geonet_connect/api/smsgate-webhook.php`. Omit `/geonet_connect` if your installation is at the domain root. If selecting a device, use the same device configured in GeoNet. Wait for webhook synchronization with the phone.
4. Text `DOWN SUB-1008` from the number registered to that connection to the gateway SIM. Open GeoNet's SMS inbox and refresh. Text `RESTORED SUB-1008` to confirm restoration. Substitute an actual connection ID.

Requests require a valid HMAC-SHA256 signature and a timestamp within five minutes; keep the phone and server clocks synchronized. Retries are deduplicated by device and incoming message ID. Reports from numbers that do not match the connection are rejected. The local simulator remains available on localhost. Mock mode affects outgoing sends only; signed incoming reports still update connection status while SMS is enabled.

No live send or webhook registration is performed automatically. Credentials stay in server settings and are not returned by the settings API. Protect database access and use HTTPS when entering credentials on a hosted installation.

Official documentation: https://docs.sms-gate.app/getting-started/public-cloud-server/ and https://docs.sms-gate.app/features/webhooks/

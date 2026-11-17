# Deploy GeoNet Connect to Render

## 1. Back up and upload the application

In local phpMyAdmin, select `geonet_connect`, choose Export, and download a SQL
backup containing structure and data. Keep this backup outside the repository:
it includes account and customer information. Do not run `sql/schema.sql` against
an existing database; it drops tables.

Create a private GitHub repository and upload this project, including Dockerfile
and deploy/. Do not upload database backups, certificate private keys, or passwords.
The project does not currently have a Git repository initialized.

## 2. Create a hosted MySQL database

For the free demo setup, Filess.io currently advertises a free plan with 10 MB
per database: https://docs.filess.io/docs/pricing/plans/ . Create a MySQL database
on that plan and save its generated host, port, database name, username, and
password. Check the current allowance in the dashboard before confirming.
Use the assigned database name as DB_NAME; it may differ from geonet_connect.
Choose the assigned database before importing your local backup, and omit any
CREATE DATABASE or USE geonet_connect statements targeting a different database.
If your database exceeds the free allowance, this plan will not fit it.

Use an external MySQL provider, or a paid MySQL private service on Render with a
persistent disk. Render's managed Postgres is not compatible with this app.
Your local XAMPP MySQL database cannot be reached using 127.0.0.1 from Render.

Create an empty database and import the SQL backup from step 1 using the database
provider's import facility or MySQL Workbench. For a Render private MySQL service,
use its supported SSH/shell access to import the backup; its private hostname
is available only to services in the same Render region and private network.
Keep the MySQL hostname, port, database name, username, and password available.
Allow connections from the Render service's outbound IP ranges if the database
provider requires an IP allowlist.

For an external provider requiring TLS, download its CA certificate. Add it to
Render as a secret file named `mysql-ca.pem` and set DB_SSL_CA to
`/etc/secrets/mysql-ca.pem`. Server certificate verification stays enabled.

## 3. Create the Render web service

In Render, choose New > Web Service and connect the GitHub repository.

| Setting | Value |
| --- | --- |
| Name | geonet-connect (or another available name) |
| Language / runtime | Docker |
| Branch | Your uploaded branch, usually main |
| Root directory | Leave blank when Dockerfile is at repository root |
| Dockerfile path | ./Dockerfile |
| Region | Same region as MySQL when using a Render private database |
| Instance | Free for a demo, paid for an always-on service |
| Health check path | /index.php |

Leave Docker Command empty to use the image's Apache startup command. Apache
listens on port 10000. Add these environment variables before deploying:

| Variable | Value |
| --- | --- |
| PORT | 10000 |
| DB_HOST | Hosted MySQL hostname, without a protocol prefix |
| DB_PORT | Provider's MySQL port, usually 3306 |
| DB_NAME | Name of the imported database |
| DB_USER | MySQL username |
| DB_PASSWORD | MySQL password |
| DB_SSL_CA | Only when needed: /etc/secrets/mysql-ca.pem |

Click Deploy Web Service and wait for the build and deployment to finish.
Database passwords belong in Render's environment settings, not source files.
XAMPP continues to use the original local defaults when these variables are unset.

## 4. Verify the deployed system

Open the service's HTTPS onrender.com URL. Sign in with an account from the
imported database. Check the dashboard, map, sign-up help dialog, and sign-out.
Use a test subscriber request to verify database writes, and check the admin
subscription request list. SMS requires valid provider settings and credit.
If you imported demo accounts, change their passwords through the profile page.

The Docker deployment deliberately blocks install.php because the installer can
erase existing data. Import SQL before testing sign-in. The health check verifies
the login page responds; it does not verify database connectivity.

## Troubleshooting

- Build fails: inspect Render's build logs and confirm Dockerfile and deploy/
  were uploaded at the expected paths.
- Database not ready: check database import, environment variables, TLS CA,
  database allowlist, and private-service region. Do not use the public installer.
- Login works locally but not online: use the hosted database credentials, and
  visit HTTPS because production session cookies require it.
- First request is slow: free Render web services sleep after inactivity.
- Users are logged out after deployment/restart: sessions currently live on the
  container's temporary filesystem. A shared session store is needed before
  scaling to multiple web instances.

References:
- https://render.com/docs/docker
- https://render.com/docs/web-services
- https://render.com/docs/configure-environment-variables
- https://render.com/docs/deploy-mysql
- https://render.com/docs/free

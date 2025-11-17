# Trail Agent

A WordPress-based trail conditions reporting system for managing and tracking trail maintenance.

## Features

- Submit trail condition reports with photos
- Browse and filter trail reports
- Track outstanding maintenance needs
- Analytics and leaderboard
- Interactive trail map
- User registration and management

## Setup

### Prerequisites

- Docker and Docker Compose
- Git

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/TrentMahaffey/trailagent.git
   cd trailagent
   ```

2. Create your environment configuration:
   ```bash
   cp .env.example .env
   ```

3. Edit `.env` and set your WordPress URL:
   ```bash
   # Change to your machine's IP address or domain
   WORDPRESS_URL=http://YOUR_IP_ADDRESS:8082
   ```

   Examples:
   - Local access only: `WORDPRESS_URL=http://localhost:8082`
   - Network access: `WORDPRESS_URL=http://192.168.0.110:8082`
   - Domain: `WORDPRESS_URL=https://yourdomain.com`

4. Start the containers:
   ```bash
   docker compose up -d --build
   ```

   The first startup will automatically:
   - Install WordPress
   - Activate the Trail Conditions Reports plugin
   - Create all required pages
   - Configure permalinks
   - Set up the home page

5. Access your site:
   - **Website**: http://YOUR_IP_ADDRESS:8082
   - **Admin**: http://YOUR_IP_ADDRESS:8082/wp-admin
   - **Username**: admin
   - **Password**: admin

### Services

After starting, the following services will be available:

- **WordPress**: http://YOUR_IP_ADDRESS:8082
- **phpMyAdmin**: http://YOUR_IP_ADDRESS:8081
- **MailHog** (email testing): http://YOUR_IP_ADDRESS:8025
- **Database**: localhost:3307

## Usage

### For Trail Volunteers

1. Register for an account at `/register`
2. Log in and submit trail reports at `/submit`
3. View reports at `/reports`
4. Check outstanding maintenance needs at `/outstanding-maintenance`

### For Administrators

- Manage outstanding maintenance: `/maintenance-admin`
- View analytics: `/analytics`
- WordPress admin: `/wp-admin`

## Populating Test Data

After the initial setup, you can populate the site with test trails and fake reports:

### 1. Seed Trails and Areas

This creates trails from the seed data (Aspen, Snowmass, etc.):

```bash
docker compose exec --user www-data wordpress \
  wp eval-file wp-content/plugins/trail-conditions-reports/tools/seed-trails.php
```

To reset and recreate all trails:

```bash
docker compose exec --user www-data wordpress \
  wp eval-file wp-content/plugins/trail-conditions-reports/tools/seed-trails.php -- --reset
```

### 2. Import GPX Files (Optional)

If you have GPX trail data, you can import it for the interactive trail map:

1. Place ZIP files in the `gpx_files` directory
   - Name them like: `Aspen_GPX.zip`, `Snowmass_GPX.zip`
   - Each ZIP should contain `.gpx` files for trails in that area

2. Import via WordPress admin:
   - Go to **Trails → Trail Map Manager**
   - Click **Import GPX Files**

The seeded trails have metadata (length, difficulty, elevation) but no GPS coordinates. GPX files are not included in the repo due to size.

### 3. Import Fake Trail Reports

This generates fake trail reports using photos from the `import_photos` directory:

```bash
docker compose exec wordpress \
  wp eval-file /var/www/html/wp-content/plugins/trail-conditions-reports/tools/import-fake-reports.php --allow-root
```

The script will:
- Create 200 random trail reports
- Assign them to random trails and users
- Use photos from the `import_photos` directory
- Add realistic maintenance needs and conditions

## Development

### Rebuilding the Container

```bash
docker compose down
docker compose up -d --build
```

### Fresh Install

To start completely fresh (will delete all data):

```bash
docker compose down
docker volume rm trailagent_dbdata
docker compose up -d --build
```

### Viewing Logs

```bash
docker compose logs -f wordpress
```

### Other Useful Tools

**Deduplicate trails:**
```bash
docker compose exec --user www-data wordpress \
  wp eval-file wp-content/plugins/trail-conditions-reports/tools/dedupe-trails.php
```

## License

[Add your license here]

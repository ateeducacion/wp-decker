# Installation

## Requirements

- WordPress 6.1+
- PHP 8.4+
- Modern browser

## Install from GitHub release

1. Download the latest release ZIP from the [Releases page](https://github.com/ateeducacion/wp-decker/releases).
2. In WordPress go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and activate the plugin.
4. Go to **Settings → Decker** and configure the options you need.

## Development install

```bash
git clone https://github.com/ateeducacion/wp-decker.git
cd wp-decker
composer install
make up
```

WordPress will be available at `http://localhost:8888`  
User: `admin` / Password: `password`

## Notes

- The plugin is intended for internal use at ATE. It is **not** published on wordpress.org.
- Nextcloud import is optional and one-way only.

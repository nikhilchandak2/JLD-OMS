# Logo Setup Instructions

## On Your Server

### Step 1: Create the directory (if it doesn't exist)

```bash
cd /var/www/tracking
mkdir -p public/assets/images
chown -R www-data:www-data public/assets/images
chmod -R 755 public/assets/images
```

### Step 2: Upload your logo file

You have several options:

#### Option A: Using SCP (from your local Windows machine)

```powershell
# In PowerShell on your Windows machine
scp "C:\path\to\your\logo.png" root@159.65.158.140:/var/www/tracking/public/assets/images/jld-logo.png
```

#### Option B: Using SFTP client (FileZilla, WinSCP, etc.)

1. Connect to your server: `159.65.158.140`
2. Navigate to: `/var/www/tracking/public/assets/images/`
3. Upload your logo file as `jld-logo.png`

#### Option C: Direct upload via server console

1. Copy your logo file content
2. On server, create the file:
```bash
cd /var/www/tracking/public/assets/images
nano jld-logo.png
# Paste your logo file content (if it's text-based like SVG)
# Or use base64 encoding for binary files
```

#### Option D: Download from URL (if your logo is hosted online)

```bash
cd /var/www/tracking/public/assets/images
wget https://your-logo-url.com/logo.png -O jld-logo.png
```

### Step 3: Verify the file

```bash
ls -lh /var/www/tracking/public/assets/images/jld-logo.png
file /var/www/tracking/public/assets/images/jld-logo.png
```

### Step 4: Set proper permissions

```bash
chown www-data:www-data /var/www/tracking/public/assets/images/jld-logo.png
chmod 644 /var/www/tracking/public/assets/images/jld-logo.png
```

## Logo Requirements

- **File name:** `jld-logo.png` (exact name)
- **Formats supported:** PNG, JPG, SVG
- **Recommended size:**
  - Login page: 80px height
  - Header: 40px height
- **Location:** `/var/www/tracking/public/assets/images/jld-logo.png`

## Testing

After uploading, refresh your browser:
- Login page: `https://oms.jldminerals.com/login`
- Dashboard: `https://oms.jldminerals.com/dashboard`

If the logo doesn't appear, check:
1. File exists: `ls -la /var/www/tracking/public/assets/images/`
2. File permissions: `ls -l /var/www/tracking/public/assets/images/jld-logo.png`
3. Nginx can read it: `sudo -u www-data cat /var/www/tracking/public/assets/images/jld-logo.png > /dev/null`
4. Browser console for 404 errors

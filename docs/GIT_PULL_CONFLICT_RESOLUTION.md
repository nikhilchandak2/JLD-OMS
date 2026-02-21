# Resolving Git Pull Conflict for Logo File

## Problem
Git is trying to overwrite your manually uploaded logo file with a version from the repository.

## Solution

### Option 1: Keep Your Uploaded Logo (Recommended)

Since you already uploaded your actual logo file, keep it and remove the Git-tracked version:

```bash
cd /var/www/tracking

# Remove the Git-tracked logo file (your uploaded one will remain)
rm public/assets/images/jld-logo.png

# Pull the changes
git pull

# Your actual logo file should still be there (if it was uploaded correctly)
# If not, re-upload it after pulling
```

### Option 2: Backup and Replace

If you want to be extra safe:

```bash
cd /var/www/tracking

# Backup your current logo
cp public/assets/images/jld-logo.png public/assets/images/jld-logo.png.backup

# Remove the file
rm public/assets/images/jld-logo.png

# Pull changes
git pull

# Restore your logo
mv public/assets/images/jld-logo.png.backup public/assets/images/jld-logo.png
```

### Option 3: Add to .gitignore (Future-proof)

After pulling, add the logo to `.gitignore` so Git doesn't track it:

```bash
cd /var/www/tracking

# Add logo to .gitignore
echo "public/assets/images/jld-logo.png" >> .gitignore

# Commit the .gitignore change (optional)
git add .gitignore
git commit -m "Ignore logo file - uploaded manually"
```

## Verify Logo After Pull

```bash
# Check if logo exists
ls -lh /var/www/tracking/public/assets/images/jld-logo.png

# Check file permissions
chown www-data:www-data /var/www/tracking/public/assets/images/jld-logo.png
chmod 644 /var/www/tracking/public/assets/images/jld-logo.png
```

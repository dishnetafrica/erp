#!/bin/bash

##############################################################################
# ISP ERP Platform - Installation & Setup Script
# Version: 2.0.0
# 
# This script helps with initial setup and deployment
##############################################################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Project info
PROJECT_NAME="ISP ERP Platform"
VERSION="2.0.0"

echo -e "${BLUE}"
echo "╔════════════════════════════════════════════════════════════╗"
echo "║                                                            ║"
echo "║          ISP ERP Platform v2.0 - Installation             ║"
echo "║       Enterprise Accounting System for ISPs                ║"
echo "║                                                            ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
   echo -e "${RED}Warning: Running as root. This may cause permission issues.${NC}"
   read -p "Continue anyway? (y/n) " -n 1 -r
   echo
   if [[ ! $REPLY =~ ^[Yy]$ ]]; then
       exit 1
   fi
fi

# Function to print status
print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

# Check PHP version
echo ""
print_info "Checking system requirements..."

if ! command -v php &> /dev/null; then
    print_error "PHP is not installed"
    exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_VERSION;')
print_status "PHP version: $PHP_VERSION"

if ! php -r 'exit(version_compare(PHP_VERSION, "7.4.0", ">=") ? 0 : 1);'; then
    print_error "PHP 7.4 or higher is required"
    exit 1
fi

# Check required PHP extensions
print_info "Checking PHP extensions..."

REQUIRED_EXTENSIONS=("pdo" "pdo_sqlite" "json" "mbstring" "curl")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "^$ext$"; then
        MISSING_EXTENSIONS+=("$ext")
        print_error "Missing PHP extension: $ext"
    else
        print_status "Extension available: $ext"
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -ne 0 ]; then
    echo ""
    print_error "Please install missing PHP extensions: ${MISSING_EXTENSIONS[*]}"
    exit 1
fi

# Check directory structure
echo ""
print_info "Verifying project structure..."

REQUIRED_DIRS=(
    "database/migrations"
    "database/seeds"
    "src/Core"
    "src/Services"
    "src/Helpers"
    "src/Config"
    "data"
)

for dir in "${REQUIRED_DIRS[@]}"; do
    if [ -d "$dir" ]; then
        print_status "Directory exists: $dir"
    else
        print_warning "Creating directory: $dir"
        mkdir -p "$dir"
    fi
done

# Check required files
REQUIRED_FILES=(
    "manifest.json"
    "main.php"
    "public.php"
    "README.md"
    "database/migrations/001_initial_schema.php"
    "database/seeds/001_default_data.php"
    "src/Core/Application.php"
    "src/Services/UispSyncService.php"
    "src/Helpers/functions.php"
    "src/Config/Database.php"
)

echo ""
print_info "Checking required files..."

MISSING_FILES=()

for file in "${REQUIRED_FILES[@]}"; do
    if [ -f "$file" ]; then
        print_status "File exists: $file"
    else
        MISSING_FILES+=("$file")
        print_error "Missing file: $file"
    fi
done

if [ ${#MISSING_FILES[@]} -ne 0 ]; then
    echo ""
    print_error "Required files are missing. Please ensure all files are in place."
    exit 1
fi

# Set permissions
echo ""
print_info "Setting file permissions..."

chmod 755 main.php
chmod 755 public.php
chmod 755 -R data/
print_status "Permissions set"

# Create data directory structure
echo ""
print_info "Setting up data directory..."

mkdir -p data/uploads
mkdir -p data/logs
mkdir -p data/backups

print_status "Data directory structure created"

# Check if database exists
if [ -f "data/isp_erp.db" ]; then
    print_warning "Database already exists at data/isp_erp.db"
    read -p "Do you want to reset the database? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        print_info "Backing up existing database..."
        cp data/isp_erp.db "data/backups/isp_erp_$(date +%Y%m%d_%H%M%S).db"
        rm data/isp_erp.db
        print_status "Database backed up and removed"
    fi
fi

# Test database creation
echo ""
print_info "Testing database initialization..."

php -r "
\$db = new PDO('sqlite:data/test.db');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo 'Database test successful';
"

if [ $? -eq 0 ]; then
    print_status "Database functionality verified"
    rm -f data/test.db
else
    print_error "Database test failed"
    exit 1
fi

# Installation options
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Installation Options${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""
echo "1) Install as UISP Plugin (recommended)"
echo "2) Standalone Installation"
echo "3) Development Setup"
echo "4) Skip (Manual setup later)"
echo ""
read -p "Select option (1-4): " -n 1 -r INSTALL_OPTION
echo ""

case $INSTALL_OPTION in
    1)
        echo ""
        print_info "Installing as UISP Plugin..."
        
        # Check for UISP installation
        UISP_PLUGIN_DIR="/data/ucrm/ucrm/data/plugins"
        
        if [ ! -d "$UISP_PLUGIN_DIR" ]; then
            print_error "UISP plugin directory not found: $UISP_PLUGIN_DIR"
            print_info "Please ensure UISP is installed or specify custom path"
            read -p "Enter UISP plugin directory path: " UISP_PLUGIN_DIR
        fi
        
        if [ -d "$UISP_PLUGIN_DIR" ]; then
            TARGET_DIR="$UISP_PLUGIN_DIR/isp-erp-platform"
            
            if [ -d "$TARGET_DIR" ]; then
                print_warning "Plugin already exists at $TARGET_DIR"
                read -p "Overwrite? (y/n) " -n 1 -r
                echo
                if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                    exit 1
                fi
                rm -rf "$TARGET_DIR"
            fi
            
            print_info "Copying files to $TARGET_DIR..."
            cp -r . "$TARGET_DIR"
            
            print_status "Plugin installed successfully!"
            print_info "Access it in UISP: System → Plugins → ISP ERP Platform"
        else
            print_error "Invalid UISP plugin directory"
            exit 1
        fi
        ;;
        
    2)
        print_info "Standalone installation selected"
        print_info "Configure web server to point to public.php"
        print_status "Installation complete"
        ;;
        
    3)
        print_info "Development setup selected"
        print_status "Ready for development"
        ;;
        
    4)
        print_info "Manual setup selected"
        ;;
        
    *)
        print_error "Invalid option"
        exit 1
        ;;
esac

# Final summary
echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                                                            ║${NC}"
echo -e "${GREEN}║              Installation Complete! ✓                      ║${NC}"
echo -e "${GREEN}║                                                            ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

print_status "ISP ERP Platform v$VERSION is ready"
echo ""

print_info "Next Steps:"
echo "  1. Configure UISP connection in Settings"
echo "  2. Run initial UISP sync"
echo "  3. Review chart of accounts"
echo "  4. Configure expense categories"
echo "  5. Set up bank accounts"
echo ""

print_info "Documentation:"
echo "  • README.md - Complete user guide"
echo "  • PROJECT_ARCHITECTURE.md - System architecture"
echo "  • IMPLEMENTATION_GUIDE.md - Development roadmap"
echo ""

print_info "Quick Commands:"
echo "  • View logs: tail -f data/app.log"
echo "  • Backup DB: cp data/isp_erp.db data/backups/backup.db"
echo "  • Reset DB: rm data/isp_erp.db (will auto-recreate)"
echo ""

echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Thank you for using ISP ERP Platform!${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""

No composer.lock file present. Updating dependencies to latest instead of installing from lock file. See https://getcomposer.org/install for more information.
Loading composer repositories with package information
Updating dependencies
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires phpoffice/phpspreadsheet ^1.29 -> satisfiable by phpoffice/phpspreadsheet[1.30.0, 1.30.1, 1.30.2].
    - phpoffice/phpspreadsheet[1.30.0, ..., 1.30.2] require ext-gd * -> it is missing from your system. Install or enable PHP's gd extension.

To enable extensions, verify that they are enabled in your .ini files:
    - /etc/php/8.1/cli/php.ini
    - /etc/php/8.1/cli/conf.d/10-mysqlnd.ini
    - /etc/php/8.1/cli/conf.d/10-opcache.ini
    - /etc/php/8.1/cli/conf.d/10-pdo.ini
    - /etc/php/8.1/cli/conf.d/15-xml.ini
    - /etc/php/8.1/cli/conf.d/20-calendar.ini
    - /etc/php/8.1/cli/conf.d/20-ctype.ini
    - /etc/php/8.1/cli/conf.d/20-curl.ini
    - /etc/php/8.1/cli/conf.d/20-dom.ini
    - /etc/php/8.1/cli/conf.d/20-exif.ini
    - /etc/php/8.1/cli/conf.d/20-ffi.ini
    - /etc/php/8.1/cli/conf.d/20-fileinfo.ini
    - /etc/php/8.1/cli/conf.d/20-ftp.ini
    - /etc/php/8.1/cli/conf.d/20-gettext.ini
    - /etc/php/8.1/cli/conf.d/20-iconv.ini
    - /etc/php/8.1/cli/conf.d/20-mbstring.ini
    - /etc/php/8.1/cli/conf.d/20-mysqli.ini
    - /etc/php/8.1/cli/conf.d/20-pdo_mysql.ini
    - /etc/php/8.1/cli/conf.d/20-phar.ini
    - /etc/php/8.1/cli/conf.d/20-posix.ini
    - /etc/php/8.1/cli/conf.d/20-readline.ini
    - /etc/php/8.1/cli/conf.d/20-shmop.ini
    - /etc/php/8.1/cli/conf.d/20-simplexml.ini
    - /etc/php/8.1/cli/conf.d/20-sockets.ini
    - /etc/php/8.1/cli/conf.d/20-sysvmsg.ini
    - /etc/php/8.1/cli/conf.d/20-sysvsem.ini
    - /etc/php/8.1/cli/conf.d/20-sysvshm.ini
    - /etc/php/8.1/cli/conf.d/20-tokenizer.ini
    - /etc/php/8.1/cli/conf.d/20-xmlreader.ini
    - /etc/php/8.1/cli/conf.d/20-xmlwriter.ini
    - /etc/php/8.1/cli/conf.d/20-xsl.ini
    - /etc/php/8.1/cli/conf.d/20-zip.ini
You can also run `php --ini` in a terminal to see which files are used by PHP in CLI mode.
Alternatively, you can run Composer with `--ignore-platform-req=ext-gd` to temporarily ignore these required extensions.
Running update with --no-dev does not mean require-dev is ignored, it just means the packages will not be installed. If dev requirements are blocking the update you have to resolve those problems.
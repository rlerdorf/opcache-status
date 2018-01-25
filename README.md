OPcache Status  [![Packagist](http://img.shields.io/packagist/v/rlerdorf/opcache-status.svg)](https://packagist.org/packages/rlerdorf/opcache-status)
---------------

A one-page opcache status page for the PHP 5.5 opcode cache.

You don't need the opcode cache installed to help out with this.
See the data-sample.php file for a dump of the data.

I know it is rather ugly, so please spruce it up. But I would like
to keep it relatively small and to a single file so it is easy to 
drop into a directory anywhere without worrying about separate css/js/php
files.

[![Screenshot](https://raw.githubusercontent.com/jamesrwhite/opcache-status/improve-readme/screenshot.png)](https://raw.githubusercontent.com/jamesrwhite/opcache-status/improve-readme/screenshot.png)

### Usage

Install the package
```
composer require --dev rlerdorf/opcache-status dev-master
```

Drop it where you want it in your public folder. Example: 
```
ln -s vendor/rlerdorf/opcache-status/opcache.php ./public/
```

Open the file in your browser. Example:
```
http://127.0.0.1:8000/opcache.php
```

This report will get populated when you hit your PHP scripts.

### TODO

 - The ability to sort the list of cached scripts by the various columns
 - A better layout that can accommodate more of the script data without looking cluttered
 - A tuning suggestion tab (need to add a couple of things to the opcache output first though)


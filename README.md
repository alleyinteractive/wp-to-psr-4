# wp-to-psr-4

Migrate a WordPress-style code base to PSR-4 file structure. For example:

```
src/example/path/to/class-file.php -> src/Example/Path/To/ClassFile.php
src/example/trait-ReusableTrait.php -> src/Example/ReusableTrait.php
src/example/interface-ReusableInterface.php -> src/Example/ReusableInterface.php
```

## Installation

You can download the latest phar from the [releases
page](https://github.com/alleyinteractive/wp-to-psr-4/releases) using the
following example:

```bash
wget https://github.com/alleyinteractive/wp-to-psr-4/releases/download/v1.0.3/wp-to-psr4.phar
chmod +x wp-to-psr4.phar
mv wp-to-psr4.phar /usr/local/bin/wp-to-psr4
```

Or you can install it globally using Composer:

```bash
composer global require alleyinteractive/wp-to-psr-4
```

## Usage

```
wp-to-psr4 path/to/convert
```

### Options

```
--dry-run
```

Prints the changes that would be made without actually making them.

```
--exclude
```

Exclude a directory/file from being converted. This option can be used multiple
times and accepts glob patterns.

```
--no-git
```

Do not run `git mv` on the files. Will only make changes to the file system.

## Credits

This project is actively maintained by [Alley
Interactive](https://github.com/alleyinteractive). Like what you see? [Come work
with us](https://alley.com/careers/).

- [Sean Fisher](https://github.com/srtfisher)
- [All Contributors](../../contributors)

## License

The GNU General Public License (GPL) license. Please see [License File](LICENSE) for more information.

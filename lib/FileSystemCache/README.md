FileSystemCache
===============

A simple PHP class for caching data in the filesystem.  Major features include:

*    Support for TTL when storing data
*    Support for "Newer Than" parameter when retrieving data
*    Every call is an atomic operation with proper file locking
*    Can group cache keys together for easy invalidation
*    Composer support
*    PHPUnit tests

[![Build Status](https://secure.travis-ci.org/jdorn/FileSystemCache.png?branch=master)](http://travis-ci.org/jdorn/FileSystemCache)

Getting Started
------------------
FileSystemCache can be installed with Composer or downloaded manually.

### With Composer

If you're already using Composer, just add `jdorn/file-system-cache` to your `composer.json` file.
FileSystemCache works with Composer's autoloader out of the bat.
```js
{
	"require": {
		"jdorn/file-system-cache": "dev-master"
	}
}
```

### Manually

If you aren't using Composer, you just need to include `lib/FileSystemCache.php` in your script.

```php
require_once("path/to/FileSystemCache.php");
```

Setting the Cache Directory
-----------------------

By default, all cached data is stored in the `cache` directory relative to the currently executing script.
You can change this by setting the $cacheDir static property.

```php
<?php
FileSystemCache::$cacheDir = '/tmp/cache';
```

FileSystemCache needs write access to the cache directory.  
It's easiest if Apache (or whatever web server you're using) owns the directory.

Cache Keys
------------------------

All of FileSystemCache's methods operate on Cache Keys.  There is a `generateCacheKey` method that returns a Cache Key object.

You can pass in almost anything as the key data (array, object, string, number).  Any non-strings will be serialized and hashed.

```php
<?php
//array of data
$key_data = array(
	'user_id'=>1001,
	'ip address'=>'10.1.1.1'
);

//string
$key_data = 'my_key';

//object
$key_data = new SomeObject();

//number
$key_data = 1005;


//generate a key object
$key = FileSystemCache::generateCacheKey($key_data);
```

You can group cache keys together to better organize your data and make invalidation easier.

```php
<?php
$key_data = 'my_key';

//store in root directory (same as leaving out second parameter)
$key = FileSystemCache::generateCacheKey($key_data, null);

//store in 'group1' directory
$key = FileSystemCache::generateCacheKey($key_data, 'group1');

//store in 'group1/subgroup' directory
$key = FileSystemCache::generateCacheKey($key_data, 'group1/subgroup');
```

The resulting file structure will look like:

```
$cacheDir/
| +- my_key.cache
| +- group1/
|    | +- my_key.cache
|    | +- subgroup/
|    |    | +- my_key.cache
```

Store
------------------
Data is serialized before storing, so you can use strings, array, objects, or numbers.

```php
$data = array(
	'this'=>'is some data I want to cache',
	'it'=>'can be a string, array, object, or number.'
);

$key = FileSystemCache::generateCacheKey('mykey');

FileSystemCache::store($key, $data);
```

If you want the data to expire automatically after a set amount of time, use the optional `ttl` parameter.

```php
// Expire automatically after 1 hour (3600 seconds)
FileSystemCache::store($key, $data, 3600);
```

Retrieve
--------------------
You retrieve data using the same cache key you used to store it.  `False` will be returned if the data was not cached or expired.

```php
$data = FileSystemCache::retrieve($key);

// If there was a cache miss
if($data === false) {
	...
}
```

You can specify a `newer than` timestamp to only retrieve cached data that was stored after a certain time.
This is useful for storing a compiled version of a source file.

```php
$file = 'source_file.txt';
$modified = filemtime($file);

$key = FileSystemCache::generateCacheKey($file);

$data = FileSystemCache::retrieve($key, $modified);

// If there was a cache miss
if($data === false) {
	...
}
```

Get and Modify
------------------
There is an atomic `Get and Modify` method as well.

```php
FileSystemCache::getAndModify($key, function($value) {
	$value->count++;
	
	return $value;
});
```

If the data was originally cached with a TTL, you can pass `true` as the 3rd parameter to resset the TTL.  
Otherwise, it will be based on the original time it was stored.


Invalidate
-------------------
You can invalidate a single cache key or a group of cache keys.

```php
FileSystemCache::invalidate($key);

FileSystemCache::invalidateGroup('mygroup');
```

Invalidating a group is done recursively by default and all sub-groups will also be invalidated.
If you pass `false` as the 2nd parameter, you can make it non-recursive.

```php
FileSystemCache::invalidateGroup('mygroup', false);
```

Running the Tests
------------------
You need PHPUnit installed to run the tests.  Configuration is defined in `phpunit.xml.dist`.  Running the tests is easy:

```
phpunit
```

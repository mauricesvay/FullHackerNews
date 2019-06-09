# FullHackerNews

Read all Hacker News articles in one single static page, optimized for reading.
I use it to load all articles for offline reading on my iPhone.

Can work with any other feed.

# Requirement
* PHP >= 5.6.0
* Amazon S3 account

# Installing

* Make the `cache` folder writable
* Create an S3 bucket, configured as a Web server
* upload the content of `www` to the S3 bucket
* copy `example.env` to `.env` and update the values, or set env variables
* install dependencies : `$ php composer.phar install`
* run `php index.php` periodically
* enjoy

# License

This project is released under the BSD license.

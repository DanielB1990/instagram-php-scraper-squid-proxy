
# Squid proxy for postaddictme/instagram-php-scraper

When using [postaddictme/instagram-php-scraper](https://github.com/postaddictme/instagram-php-scraper) it is sometimes needed to set-up a proxy, so requests do not originate from a IP Address owned by a hosting provider.

I've set this up, related to [postaddictme/instagram-php-scraper - issue #1089](https://github.com/postaddictme/instagram-php-scraper/issues/1089#issuecomment-1250336588)

Added "**example-file.php**", this is set-up 'quick & dirty' and could use some modernization such as the whole `foreach($medias AS $mediaKey => $mediaValue){ ... }` part of code can be more efficient.

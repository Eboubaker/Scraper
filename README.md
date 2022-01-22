# Scrapper
PHP cli-based program to scrap media from websites that only work with javascript.

## Requirements
- php >= 7.4
- composer
- php-curl extension
- a webdriver

# Installation
```console
git clone https://github.com/Eboubaker/Scrapper
cd scrapper
composer install
```
# Usage
Test if the program works (this will download an image from a Reddit post)
```console
composer test
```
Download an image from Reddit
```console
composer scrap https://www.reddit.com/r/factorio/comments/lj5pb8
```

# WebDriver
This program requires a webdriver(remote browser) to work.  
By default, the program will connect to a temporary Selenium Webdriver running on docker on https://eboubaker.xyz:8800   
If you want to connect to your own [WebDriver](https://www.selenium.dev/documentation/webdriver) then change the driverUrl in `src/scrapper.php`
```php
function main(){
    $driverUrl = "https://localhost:4444";
```
# RoadMap
#### 🔃 Implement Reddit scrapper
- ✅ download image from a post url
- 🔃 download video from a post url
- 🔃 download GIF from a post url
- 🔃 download media from user feed
### 🔃 Implement Facebook scrapper
- 🔃 download image from a post url
- 🔃 download video from a post url
- 🔃 download media from user feed
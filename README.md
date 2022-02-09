# Scrapper
Media Web-Scrapper.  
With this tool you can download videos & images from popular websites.

# Quickstart

Directly run with docker

```bash
docker run -it --rm eboubaker/scrapper <args>
```

or build the image yourself

```bash
git clone https://github.com/Eboubaker/Scrapper
cd Scrapper
docker build -t eboubaker/scrapper .
docker run -it --rm eboubaker/scrapper <args>
```

> Run without arguments to see usage message

# Development

### Requirements

- php >= 7.4
- composer
- php-curl extension
- php-dom extension
- php-json extension
- php-mbstring extension

```console
git clone https://github.com/Eboubaker/Scrapper
cd scrapper
composer install
php src/scrapper.php <args>
```
> You *might* need to remove composer.lock before doing `composer install`

# RoadMap

### 🔃 Reddit scrapper

- 🔃 download image from a post url
- 🔃 download video from a post url
- 🔃 download GIF from a post url

### 🔃 Facebook scrapper

- ✅ download image from a post url
- ✅ download video from a post url
- 🔃 cover edge cases

### 🔃 Youtube scrapper

- 🔃 download video
- 🔃 download playlist

### 🔃 Instagram scrapper

- 🔃 download image from a post url
- 🔃 download video from a post url
- 🔃 download video from a story
- 🔃 download image from a story

# Scrapper

With this tool you can download videos & images from popular websites.

# Quickstart

Download [latest release](https://github.com/Eboubaker/Scrapper/releases/latest) or directly run with Docker

```bash
docker run -it --rm -v %cd%:/app/downloads eboubaker/scrapper <args>
```

> Replace `%cd%` with `$(pwd)` on linux

USAGE

```
usage: scrap [<options>] [<args>]

Download media from a post url

OPTIONS
  --header, -h    Add a header to the request (like Cookie), can be repeated
  --help, -?      Display this help.
  --output, -o    set output directory, default is current working directory
  --verbose, -v   display more useful information
  --version       show version

ARGUMENTS
  url   Post Url
```

# RoadMap

### Facebook scrapper

- ✅ download image from a post url
- ✅ download video from a post url

### Reddit scrapper

- ◼ download image from a post url
- ◼ download video from a post url
- ◼ download GIF as video from a post url

### Youtube scrapper

- ◼ download video
- ◼ download playlist

### Instagram scrapper

- ◼ download image from a post url
- ◼ download video from a post url
- ◼ download video from a story
- ◼ download image from a story

### ◼ Make a web interface.

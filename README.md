# Scraper

With this tool you can download videos & images from popular websites.

# Quickstart

Download [latest windows release](https://github.com/Eboubaker/Scraper/releases/latest).  
for linux you can build the tool with composer.

### Docker

an alternative is to run the tool with docker which will skip all requirements

```bash
docker run -it --rm -v %cd%:/downloads eboubaker/scraper <args>
```

> Replace `%cd%` with `$(pwd)` on linux.  
> Output will be on the current terminal directory.

USAGE

```
usage: scrap.php [<options>] [<args>]

Download media from a post url

OPTIONS
  --header, -H    Add a header to the request (like Cookie), can be repeated
  --help, -?      Display this help.
  --output, -o    set output directory, default is current working directory
  --quality, -q   default: prompt, for videos only, changes quality selection
                  behavior, allowed values: prompt,highest,high,saver where
                  highest chooses the highest quality regardless of size, high
                  will pick the best quality and will consider video size, saver
                  will try to pick a lower size video
  --verbose, -v   display more useful information while running
  --version       show version

ARGUMENTS
  url   Post Url
```

# RoadMap

### Facebook scraper

- [x] download image from a post url
- [x] download video from a post url

### Reddit scraper

- [ ] download image from a post url
- [ ] download video from a post url
- [ ] download GIF as video from a post url

### Youtube scraper

- [x] download video
- [ ] download playlist

### Instagram scraper

- [ ] download image from a post url
- [ ] download video from a post url
- [ ] download video from a story
- [ ] download image from a story

### Tiktok scraper

- [x] download tiktok video

### Make a web interface.
- [ ] [online] paste post link and get download link(s)

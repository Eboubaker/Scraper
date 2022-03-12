#!/usr/bin/env sh
if [ "$#" -ne 1 ]; then
    echo "Illegal number of parameters"
    exit
fi
sed -i -r "s/(if\s\(App::args\(\)->getOpt\('version'\)\)\sdie\(\"v).+?(\"\s\.\sPHP_EOL\))/\1${1##*v}\2/g" ./src/App.php
sed -i -r "s/(\"version\": \").+?(\",)/\1${1##*v}\2/g" ./composer.json

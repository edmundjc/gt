#!/bin/sh

#                 Get Last number in each line | remove quote  |     math       | remove quote & whitespace   | reorder
echo 'age,count' && grep -o '[0-9]\+\"*$' "$*" | sed 's/\"//g' | sort | uniq -c | sed 's/^[ \t]*//g;s/\ /,/g' | awk '{print $2,$1}'
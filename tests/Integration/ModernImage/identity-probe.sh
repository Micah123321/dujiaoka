#!/bin/sh
set -eu
printf "uid="
id -u
printf "gid="
id -g
printf "uid_map="
awk '{print $1 ":" $2 ":" $3}' /proc/self/uid_map
printf "gid_map="
awk '{print $1 ":" $2 ":" $3}' /proc/self/gid_map
sed -n '/CapEff/p;/CapAmb/p;/NoNewPrivs/p' /proc/self/status

#!/usr/bin/env bash
IP=152.53.150.194
REV=$(echo "$IP" | awk -F. '{print $4"."$3"."$2"."$1}')
for bl in zen.spamhaus.org bl.spamcop.net b.barracudacentral.org dnsbl.sorbs.net; do
  r=$(host "${REV}.${bl}" 2>/dev/null | grep -i 'address' || echo 'not listed')
  echo "${bl}: ${r}"
done

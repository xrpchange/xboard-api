#!/usr/bin/env python3
"""
Quick RBL / blocklist monitoring for Postfix (mail.xdexboard.com).

Purpose:
- Scan /var/log/mail.log for the last 24 hours (by timestamp in log lines)
- Detect deliveries rejected or deferred due to RBL / reputation problems
  (Spamhaus, Abusix, Cloudmark, DNSBL, etc.)
- Print a compact summary to stdout so it can be run manually on the server.

Usage (on server, as root or user with access to /var/log/mail.log):

    python3 /path/to/check_rbl_blocks.py

Optional arguments:

    --log /custom/path/to/mail.log
        Path to log file (default: /var/log/mail.log)

    --hours 24
        Time window in hours to look back (default: 24)
"""

import argparse
import datetime as dt
import os
import re
import sys
from collections import Counter, defaultdict
from typing import Iterable, Tuple


RBL_PATTERNS = [
    "blocked using Spamhaus",
    "Spamhaus SBL",
    "Abusix Mail Intelligence",
    "IP blocked by Abusix",
    "found on one or more DNSBLs",
    "DNSBLs, see https://postmaster.comcast.net",
    "Cloudmark CSI-Global",
    "has a poor reputation on Cloudmark",
    "Gmail has detected that this message is likely unsolicited mail",
]


LOG_TS_RE = re.compile(r"^(?P<month>\w{3})\s+(?P<day>\d{1,2})\s+(?P<time>\d{2}:\d{2}:\d{2})\s+")


def parse_log_timestamp(line: str, year: int) -> dt.datetime | None:
    """
    Parse the syslog-style timestamp at the beginning of a Postfix log line.
    Example: "Apr 01 13:00:07 mail postfix/smtp[...]: ..."
    """
    m = LOG_TS_RE.match(line)
    if not m:
        return None
    month_str = m.group("month")
    day = int(m.group("day"))
    time_str = m.group("time")
    try:
        # Use current year; logs do not contain year.
        ts = dt.datetime.strptime(f"{year} {month_str} {day} {time_str}", "%Y %b %d %H:%M:%S")
    except ValueError:
        return None
    return ts


def iter_recent_lines(path: str, hours: int) -> Iterable[str]:
    """
    Yield lines from the log file that fall within the last `hours` hours.
    We do a single forward scan and drop lines older than the window.
    """
    now = dt.datetime.now()
    window_start = now - dt.timedelta(hours=hours)
    year = now.year

    if not os.path.exists(path):
        print(f"Log file not found: {path}", file=sys.stderr)
        return

    with open(path, "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            ts = parse_log_timestamp(line, year)
            if ts is None:
                continue
            if ts < window_start:
                # Older than the window; skip.
                continue
            if ts > now:
                # Future timestamps are ignored (clock issues).
                continue
            yield line.rstrip("\n")


def is_rbl_block_line(line: str) -> bool:
    lower = line.lower()
    return any(pattern.lower() in lower for pattern in RBL_PATTERNS)


def extract_recipient(line: str) -> str | None:
    """
    Extract recipient email from Postfix log line: to=<user@domain>.
    """
    m = re.search(r"to=<([^>]+)>", line)
    if not m:
        return None
    return m.group(1)


def extract_remote_host(line: str) -> str | None:
    """
    Extract remote host description after 'host ' (if present).
    Example: "host mx2c1.comcast.net[96.102.18.146] refused to talk to me: ..."
    """
    m = re.search(r"host\s+([^\s]+)", line)
    if not m:
        return None
    return m.group(1)


def summarize_rbl_blocks(lines: Iterable[str]) -> Tuple[int, dict, dict]:
    """
    Return:
      total_count,
      counts_by_domain,
      counts_by_remote_host
    """
    total = 0
    by_domain: Counter[str] = Counter()
    by_host: Counter[str] = Counter()

    for line in lines:
        if not is_rbl_block_line(line):
            continue
        total += 1
        rcpt = extract_recipient(line)
        if rcpt and "@" in rcpt:
            domain = rcpt.split("@", 1)[1].lower()
            by_domain[domain] += 1
        host = extract_remote_host(line)
        if host:
            by_host[host] += 1

    return total, dict(by_domain), dict(by_host)


def main() -> None:
    parser = argparse.ArgumentParser(description="Scan Postfix mail.log for RBL / reputation blocks.")
    parser.add_argument(
        "--log",
        dest="log_path",
        default="/var/log/mail.log",
        help="Path to mail log file (default: /var/log/mail.log)",
    )
    parser.add_argument(
        "--hours",
        dest="hours",
        type=int,
        default=24,
        help="Time window in hours to scan (default: 24)",
    )
    args = parser.parse_args()

    recent_lines = list(iter_recent_lines(args.log_path, args.hours))
    total, by_domain, by_host = summarize_rbl_blocks(recent_lines)

    print(f"RBL / reputation-related blocks for the last {args.hours}h in {args.log_path}:")
    print(f"  Total blocked deliveries: {total}")

    if not total:
        print("  No RBL-related blocks detected in this window.")
        return

    print("\n  By recipient domain (top 20):")
    for domain, count in Counter(by_domain).most_common(20):
        print(f"    {domain}: {count}")

    print("\n  By remote MX / host (top 20):")
    for host, count in Counter(by_host).most_common(20):
        print(f"    {host}: {count}")

    # Show a few sample lines to see exact errors.
    print("\n  Sample log lines:")
    shown = 0
    for line in recent_lines:
        if is_rbl_block_line(line):
            print(f"    {line}")
            shown += 1
            if shown >= 5:
                break


if __name__ == "__main__":
    main()


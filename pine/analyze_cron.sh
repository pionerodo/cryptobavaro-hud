#!/usr/bin/env bash
# Запрашивает анализ последних снапшотов и молча завершает
/usr/bin/curl -sS "https://cryptobavaro.online/analyze.php?mode=latest&limit=10" >/dev/null

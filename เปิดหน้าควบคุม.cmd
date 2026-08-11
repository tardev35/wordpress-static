@echo off
rem ไฟล์นี้ต้องเป็น ASCII ล้วนเท่านั้น
rem cmd.exe อ่านไฟล์ batch ด้วย codepage เดิมก่อนที่ chcp จะมีผล
rem ตัวอักษรไทยที่เขียนตรงนี้จะเพี้ยนจนกลายเป็นคำสั่งผิดๆ
rem ข้อความภาษาไทยทั้งหมดให้ Node หรือ type อ่านจากไฟล์ .txt แทน

chcp 65001 >nul
cd /d "%~dp0"
title WP Static Export

where node >nul 2>nul
if errorlevel 1 (
  type "tools\uploader\no-node.txt"
  pause
  exit /b 1
)

node tools\uploader\server.mjs --open

echo.
pause

import { readFileSync } from "node:fs";

export const C = {
  dim: (s) => `\x1b[2m${s}\x1b[0m`,
  red: (s) => `\x1b[31m${s}\x1b[0m`,
  green: (s) => `\x1b[32m${s}\x1b[0m`,
  yellow: (s) => `\x1b[33m${s}\x1b[0m`,
  bold: (s) => `\x1b[1m${s}\x1b[0m`,
};

/**
 * อ่าน JSON โดยตัด BOM ทิ้งก่อน — Notepad กับ PowerShell บน Windows เขียน UTF-8
 * ใส่ BOM มาให้เป็นค่าเริ่มต้น แล้ว JSON.parse จะตายทันทีที่เจอ
 *
 * @param {string} path
 * @returns {any}
 */
export function readJson(path) {
  return JSON.parse(readFileSync(path, "utf8").replace(/^﻿/, ""));
}

/**
 * @param {string} url
 * @returns {string} host แบบไม่มี www ตัวพิมพ์เล็ก คืนค่าว่างถ้าอ่านไม่ออก
 */
export function hostOf(url) {
  if (!url) return "";
  try {
    return new URL(url.startsWith("http") ? url : `https://${url}`).host.replace(/^www\./, "").toLowerCase();
  } catch {
    return "";
  }
}

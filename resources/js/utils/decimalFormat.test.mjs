// Dijalankan dengan: node --test resources/js/utils/decimalFormat.test.mjs
// Repo ini belum punya test-runner JS (vitest/jest) — dipakai node:test
// bawaan Node supaya tidak menambah dependency baru untuk satu util kecil.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDecimalID, parseTypedDecimalID } from './decimalFormat.js';

test('parseTypedDecimalID mengubah "1.000,50" menjadi desimal biasa "1000.50"', () => {
    const { plain } = parseTypedDecimalID('1.000,50');
    assert.equal(plain, '1000.50');
});

test('parseTypedDecimalID menyisipkan titik ribuan saat mengetik angka bulat panjang (kasus salah ketik 80020)', () => {
    const { display, plain } = parseTypedDecimalID('80020');
    assert.equal(display, '80.020');
    assert.equal(plain, '80020');
});

test('parseTypedDecimalID membatasi maksimal 2 digit di belakang koma', () => {
    const { display, plain } = parseTypedDecimalID('1000,5678', 2);
    assert.equal(display, '1.000,56');
    assert.equal(plain, '1000.56');
});

test('parseTypedDecimalID menangani input yang dimulai dari koma (mis. ",5")', () => {
    const { plain } = parseTypedDecimalID(',5');
    assert.equal(plain, '0.5');
});

test('parseTypedDecimalID dengan maxDecimals=0 (dipakai NumberInput untuk "Uang Diterima") memformat ribuan tanpa desimal dan tetap parse balik ke nilai presisi utuh', () => {
    const { display, plain } = parseTypedDecimalID('100000', 0);
    assert.equal(display, '100.000');
    assert.equal(plain, '100000');
    assert.equal(Number(plain), 100000);
});

test('formatDecimalID menampilkan nilai desimal dengan format Indonesia', () => {
    assert.equal(formatDecimalID('1000.5'), '1.000,5');
    assert.equal(formatDecimalID(1000000), '1.000.000');
    assert.equal(formatDecimalID(''), '');
    assert.equal(formatDecimalID(null), '');
});

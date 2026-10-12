const fs = require('fs');
const file = process.argv[2];
const c = fs.readFileSync(file, 'utf8');
let d = 0;
let inS = false, sc = '', inT = false, lc = false, bc = false;
for (let i = 0; i < c.length; i++) {
  const ch = c[i];
  const nx = i + 1 < c.length ? c[i+1] : '';
  if (lc) { if (ch === '\n') lc = false; continue; }
  if (bc) { if (ch === '*' && nx === '/') { bc = false; i++; } continue; }
  if (!inS && !inT && ch === '/') {
    if (nx === '/') { lc = true; i++; continue; }
    if (nx === '*') { bc = true; i++; continue; }
  }
  if (!inT && !inS && (ch === "'" || ch === '"')) { inS = true; sc = ch; continue; }
  if (inS && ch === sc) { inS = false; continue; }
  if (!inS && ch === '`') { inT = !inT; continue; }
  if (inS || inT) continue;
  const rem = c.substring(i);
  if (rem.startsWith('<div') && (i+4 >= c.length || ' >\n\r{'.includes(c[i+4]))) {
    d++; i += 3;
  }
  if (rem.startsWith('</div>')) {
    d--; i += 5;
  }
}
console.log(`Final div depth for ${file}: ${d}`);

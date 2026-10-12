// diagnose.js - Precisely track div nesting and report anomalies
const fs = require('fs');
const file = process.argv[2];
const c = fs.readFileSync(file, 'utf8');
const lines = c.split('\n');
let depth = 0;
let inS = false, sc = '', inT = false, lc = false, bc = false;
const openStack = []; // {line, col, context}
const events = [];

for (let i = 0; i < c.length; i++) {
  const ch = c[i];
  const nx = i + 1 < c.length ? c[i+1] : '';
  if (lc) { if (ch === '\n') lc = false; continue; }
  if (bc) { if (ch === '*' && nx === '/') { bc = false; i++; } continue; }
  if (!inS && !inT && ch === '/') { if (nx === '/') { lc = true; i++; continue; } if (nx === '*') { bc = true; i++; continue; } }
  if (!inT && !inS && (ch === "'" || ch === '"')) { inS = true; sc = ch; continue; }
  if (inS && ch === sc) { inS = false; continue; }
  if (!inS && ch === '`') { inT = !inT; continue; }
  if (inS || inT) continue;

  const ln = c.substring(0, i).split('\n').length;
  const rem = c.substring(i);

  if (rem.startsWith('<div') && (i+4 >= c.length || ' >\n\r{'.includes(c[i+4]))) {
    const ctx = lines[ln-1].trim().substring(0, 80);
    openStack.push({ line: ln, col: i, ctx });
    depth++;
    events.push({ type: 'open', line: ln, depth, ctx });
    i += 3;
  } else if (rem.startsWith('</div>')) {
    const popped = openStack.pop();
    depth--;
    events.push({ type: 'close', line: ln, depth, ctx: popped ? popped.ctx : '???' });
    i += 5;
  }
}

console.log(`File: ${file}`);
console.log(`Final div depth: ${depth}`);
if (depth !== 0) {
  console.log(`\nLast 5 unclosed divs:`);
  openStack.slice(-5).forEach(d => console.log(`  L${d.line}: ${d.ctx}`));
}

// Find where depth transitions are suspicious
// A section close that jumps depth more than expected
let prevDepth = 0;
events.forEach(e => {
  if (e.type === 'close' && prevDepth - e.depth > 1) {
    console.log(`  ANOMALY L${e.line}: depth ${prevDepth}->${e.depth} (skipped ${prevDepth-e.depth-1})`);
  }
  prevDepth = e.depth;
});

const fs = require('fs');
const c = fs.readFileSync('frontend/src/components/Dashboard/Security.jsx', 'utf8');
const lines = c.split('\n');
let depth = 0;
let inStr = false, strChar = '', inTemplate = false, inLineComment = false, inBlockComment = false;

function process(ch, next) {
  if (inLineComment) { if (ch === '\n') inLineComment = false; return; }
  if (inBlockComment) { if (ch === '*' && next === '/') { inBlockComment = false; } return; }
  if (!inStr && !inTemplate && ch === '/' && next === '/') { inLineComment = true; return; }
  if (!inStr && !inTemplate && ch === '/' && next === '*') { inBlockComment = true; return; }
  if (!inTemplate && !inStr && (ch === "'" || ch === '"')) { inStr = true; strChar = ch; return; }
  if (inStr && ch === strChar) { inStr = false; return; }
  if (!inStr && ch === '`') { inTemplate = !inTemplate; return; }
  if (inStr || inTemplate) return;
  // Not in string/template/comment
  return true;
}

// Process entire file character by character
for (let i = 0; i < c.length; i++) {
  const ch = c[i];
  const next = i + 1 < c.length ? c[i+1] : '';
  const lineNum = c.substring(0, i).split('\n').length;

  if (inLineComment) { if (ch === '\n') inLineComment = false; continue; }
  if (inBlockComment) { if (ch === '*' && next === '/') { inBlockComment = false; i++; } continue; }
  if (!inStr && !inTemplate && ch === '/' && next === '/') { inLineComment = true; i++; continue; }
  if (!inStr && !inTemplate && ch === '/' && next === '*') { inBlockComment = true; i++; continue; }
  if (!inTemplate && !inStr && (ch === "'" || ch === '"')) { inStr = true; strChar = ch; continue; }
  if (inStr && ch === strChar && c[i-1] !== '\\') { inStr = false; continue; }
  if (!inStr && ch === '`') { inTemplate = !inTemplate; continue; }
  if (inStr || inTemplate) continue;

  const rem = c.substring(i);
  if (rem.startsWith('<div') && (c[i+4] === ' ' || c[i+4] === '>' || c[i+4] === '\n' || c[i+4] === '\r' || c[i+4] === '{')) {
    depth++;
    i += 3;
  }
  if (rem.startsWith('</div>')) {
    depth--;
    i += 5;
    if (depth < 0) console.log('NEGATIVE at line ' + lineNum);
  }
}
console.log('Final div depth: ' + depth);

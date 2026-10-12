// repair-jsx.js - Find and fix unclosed divs in JSX files
// Usage: node repair-jsx.js <file>

const fs = require('fs');
const file = process.argv[2];
if (!file) { console.error('Usage: node repair-jsx.js <file>'); process.exit(1); }

const content = fs.readFileSync(file, 'utf8');
const lines = content.split('\n');

// Parse the file tracking JSX element nesting
let depth = 0;
let inStr = false, strChar = '', inTemplate = false, inLineComment = false, inBlockComment = false;
const openPositions = []; // stack of {line, col, tag}

for (let i = 0; i < content.length; i++) {
  const ch = content[i];
  const nx = i + 1 < content.length ? content[i+1] : '';

  if (inLineComment) { if (ch === '\n') inLineComment = false; continue; }
  if (inBlockComment) { if (ch === '*' && nx === '/') { inBlockComment = false; i++; } continue; }
  if (!inStr && !inTemplate && ch === '/') {
    if (nx === '/') { inLineComment = true; i++; continue; }
    if (nx === '*') { inBlockComment = true; i++; continue; }
  }
  if (!inTemplate && !inStr && (ch === "'" || ch === '"')) { inStr = true; strChar = ch; continue; }
  if (inStr && ch === strChar) { inStr = false; continue; }
  if (!inStr && ch === '`') { inTemplate = !inTemplate; continue; }
  if (inStr || inTemplate) continue;

  const rem = content.substring(i);
  const lineNum = content.substring(0, i).split('\n').length;

  // Detect opening tags: <div, <section, <aside, <main, <header, <footer, <ul, <table
  for (const tag of ['div', 'section', 'aside', 'main', 'header', 'footer', 'ul', 'table', 'tbody', 'thead', 'form', 'nav', 'span', 'label', 'strong', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'a', 'li', 'td', 'tr', 'th', 'img', 'input', 'br', 'hr']) {
    const open = `<${tag}`;
    const close = `</${tag}>`;
    if (rem.startsWith(open) && (rem.length <= tag.length + 1 || ' >/>\n\r{'.includes(rem[tag.length + 1]))) {
      // Self-closing check
      let j = i + tag.length + 1;
      while (j < content.length && content[j] !== '>' && content[j] !== '/') j++;
      if (content[j] === '/' && content[j+1] === '>') {
        // Self-closing: <br />, <input ... />, <img ... />
        i = j + 1;
        break;
      }
      depth++;
      openPositions.push({ line: lineNum, tag });
      i += tag.length;
      break;
    }
    if (rem.startsWith(close)) {
      depth--;
      if (openPositions.length > 0) openPositions.pop();
      i += tag.length + 2;
      break;
    }
  }
}

console.log(`Final div depth for ${file}: ${depth}`);
if (depth > 0) {
  console.log('Unclosed elements (last 10):');
  openPositions.slice(-10).forEach(p => {
    console.log(`  Line ${p.line}: <${p.tag}>`);
  });
} else if (depth < 0) {
  console.log('Extra closing tags!');
}

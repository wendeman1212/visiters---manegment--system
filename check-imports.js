const fs = require('fs');
const path = require('path');
function walk(dir) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach(f => {
    const fp = path.join(dir, f);
    const stat = fs.statSync(fp);
    if (stat.isDirectory()) results = results.concat(walk(fp));
    else if (f.endsWith('.jsx') || f.endsWith('.js')) results.push(fp);
  });
  return results;
}
const files = walk('frontend/src');
files.forEach(f => {
  const c = fs.readFileSync(f, 'utf8');
  if (c.includes('<AppIcon') && !c.includes('import AppIcon')) {
    console.log('MISSING: ' + path.relative('.', f));
  }
});

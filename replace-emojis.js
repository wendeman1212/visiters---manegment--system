// Replace emojis in JSX content with AppIcon, strip emojis from string literals
const fs = require('fs');
const filePath = process.argv[2];
if (!filePath) { console.error('Usage: node replace-emojis.js <file>'); process.exit(1); }

let content = fs.readFileSync(filePath, 'utf8');

// Build a comprehensive emoji regex (all common emoji ranges)
const emojiRegex = /[\u{1F000}-\u{1FFFF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{2300}-\u{23FF}\u{2B05}-\u{2B55}\u{FE00}-\u{FE0F}\u{200D}\u{20E3}\u{2934}\u{2935}\u{25AA}\u{25AB}\u{25B6}\u{25C0}\u{25FB}-\u{25FE}\u{3030}\u{303D}\u{3297}\u{3299}\u{2122}\u{2139}\u{2194}-\u{21AA}\u{23CF}\u{23E9}-\u{23F3}\u{23F8}-\u{23FA}\u{25FB}-\u{25FE}\u{2934}\u{2935}\u{2B05}-\u{2B07}\u{2B1B}\u{2B1C}\u{2B50}\u{2B55}\u{3030}\u{303D}\u{3297}\u{3299}\u{FE0F}]/gu;

// Map emoji to icon name for properties like icon:'⭐' -> icon:'star'
const emojiToIconName = {
  '📍':'location', '⏳':'clock', '✅':'check', '📋':'clipboard', '📅':'calendar',
  '👤':'user', '💬':'message', '📝':'edit', '🎫':'credit-card', '⭐':'star',
  '🔍':'search', '📊':'chart', '🔔':'bell', '⚠️':'alert', '📥':'download',
  '📤':'send', '🚪':'exit', '🛡️':'shield', '🛡':'shield', '✏️':'edit',
  '🗑️':'close', '⏱️':'clock', '⏱':'clock', '🏁':'flag', '🏢':'building',
  '👥':'users', '📌':'location', '🔄':'refresh', '👔':'user', '📷':'edit',
  '💾':'check', '📧':'email', '📱':'phone', '🌍':'globe', '💼':'building',
  '🔑':'lock', '🚨':'alert', '⚡':'alert', '🔐':'lock', '🌐':'globe',
  '🔀':'refresh', '🏷️':'grid', '🏷':'grid', '🪪':'credit-card', '🔓':'lock',
  '🔒':'lock', '🔴':'alert', '🟢':'check', '🔵':'check', '⚪':'check',
  '🟡':'alert', '🔕':'bell', '🖨️':'check', '🖨':'check', '🗑':'close',
  '📁':'clipboard', '🚀':'arrow-right', '🔗':'grid', '❌':'close',
  '❗':'alert', '❓':'search', '✨':'star', '👍':'check', '👎':'close',
  '🎉':'star', '🎊':'star', '💪':'check', '🧠':'check', '👀':'eye',
  '👋':'check', '🤝':'handshake', '👏':'check', '🙏':'check', '💰':'check',
  '🌟':'star', '😊':'check', '😢':'alert', '😡':'alert', '😎':'check',
  '💯':'check', '✔':'check', '✖':'close', '🗳️':'check', '🗳':'check',
  '🎖':'star', '🎖️':'star', '⚧️':'user', '🔽':'eye', '🔑':'lock',
  '🚨':'alert', '⏰':'clock', '⊘':'close', '✗':'close',
};

let changeCount = 0;
let result = '';
let i = 0;

while (i < content.length) {
  const ch = content[i];
  const code = ch.charCodeAt(0);
  
  // Check if this starts an emoji sequence
  let emoji = '';
  let isEmoji = false;
  
  // Check for surrogate pairs (most emoji are above 0xFFFF)
  if (code >= 0xD800 && code < 0xDC00 && i + 1 < content.length) {
    const nextCode = content.charCodeAt(i + 1);
    if (nextCode >= 0xDC00 && nextCode < 0xE000) {
      const codePoint = (code - 0xD800) * 0x400 + (nextCode - 0xDC00) + 0x10000;
      emoji = ch + content[i + 1];
      isEmoji = true;
    }
  }
  
  // Check for BMP emojis
  if (!isEmoji && ((code >= 0x2600 && code <= 0x27BF) || 
      (code >= 0x2300 && code <= 0x23FF) ||
      (code >= 0x2B05 && code <= 0x2B55) ||
      code === 0x2122 || code === 0x2139)) {
    // Check for VS16 modifier (variation selector)
    emoji = ch;
    isEmoji = true;
    // Check if next char is VS16
    if (i + 1 < content.length && content.charCodeAt(i + 1) === 0xFE0F) {
      emoji += content[i + 1];
    }
  }
  
  if (isEmoji) {
    const emojiLen = emoji.length;
    // Check if we're inside a JS string (between quotes)
    // Simple heuristic: look back for the last quote/brace context
    const beforeText = result;
    
    // Determine if this emoji is inside a string literal
    // Count unescaped quotes before this position
    let inString = false;
    let stringChar = '';
    for (let j = 0; j < beforeText.length; j++) {
      if (beforeText[j] === "'" || beforeText[j] === '"' || beforeText[j] === '`') {
        if (j === 0 || beforeText[j-1] !== '\\') {
          if (!inString) {
            inString = true;
            stringChar = beforeText[j];
          } else if (beforeText[j] === stringChar) {
            inString = false;
          }
        }
      }
    }
    
    changeCount++;
    const iconName = emojiToIconName[emoji] || emojiToIconName[emoji.replace(/\uFE0F/g, '')] || null;
    
    if (inString) {
      // Inside a string: just strip the emoji
      result += '';
    } else {
      // In JSX: replace with AppIcon
      if (iconName) {
        result += '<AppIcon name="' + iconName + '" />';
      } else {
        result += ''; // Unknown emoji, remove
      }
    }
    i += emojiLen;
  } else {
    result += ch;
    i++;
  }
}

// Fix broken strings: strip leading/trailing whitespace from AppIcon in strings
result = result.replace(/'\s*<AppIcon name="([^"]*)" \/>/g, "'");
result = result.replace(/<AppIcon name="([^"]*)" \/>[^']*'/g, "'");
// Fix icon property: icon:'<AppIcon name="x" />' -> icon:'x'
result = result.replace(/icon:'<AppIcon name="([^"]*)" \/>/g, "icon:'$1'");
result = result.replace(/icon:"<AppIcon name="([^"]*)" \/>/g, 'icon:"$1"');
// Fix icon in objects: {icon:'<AppIcon...>'} 
result = result.replace(/\{icon:'<AppIcon name="([^"]*)" \/>/g, "{icon:'$1'");

fs.writeFileSync(filePath, result, 'utf8');
console.log(`Replaced ${changeCount} emojis in ${filePath}`);

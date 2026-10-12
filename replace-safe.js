// Safe emoji replacement using simple string replace (no char-by-char processing)
// Usage: node replace-safe.js <file>
const fs = require('fs');
const filePath = process.argv[2];
if (!filePath) { console.error('Usage: node replace-safe.js <file>'); process.exit(1); }

let content = fs.readFileSync(filePath, 'utf8');

// Emoji -> AppIcon name mapping (ordered by length, longer first to avoid partial matches)
const replacements = [
  // Keyed emoji icon objects: icon:'EMOJI' -> icon:'name'
  ['\u{1F4CD}', 'location'],    // 📍
  ['\u{23F3}', 'clock'],        // ⏳
  ['\u{23F1}\u{FE0F}', 'clock'],// ⏱️
  ['\u{23F1}', 'clock'],        // ⏱
  ['\u{2705}', 'check'],        // ✅
  ['\u{1F4CB}', 'clipboard'],   // 📋
  ['\u{1F4C5}', 'calendar'],    // 📅
  ['\u{1F464}', 'user'],        // 👤
  ['\u{1F4AC}', 'message'],     // 💬
  ['\u{1F4DD}', 'edit'],        // 📝
  ['\u{1F3AB}', 'credit-card'], // 🎫
  ['\u{2B50}\u{FE0F}', 'star'], // ⭐️
  ['\u{2B50}', 'star'],         // ⭐
  ['\u{1F50D}', 'search'],      // 🔍
  ['\u{1F4CA}', 'chart'],       // 📊
  ['\u{1F514}', 'bell'],        // 🔔
  ['\u{26A0}\u{FE0F}', 'alert'],// ⚠️
  ['\u{26A0}', 'alert'],        // ⚠
  ['\u{1F4E5}', 'download'],    // 📥
  ['\u{1F4E4}', 'send'],        // 📤
  ['\u{1F6AA}', 'exit'],        // 🚪
  ['\u{1F6E1}\u{FE0F}', 'shield'], // 🛡️
  ['\u{1F6E1}', 'shield'],      // 🛡
  ['\u{270F}\u{FE0F}', 'edit'], // ✏️
  ['\u{270F}', 'edit'],         // ✏
  ['\u{1F5D1}\u{FE0F}', 'close'],// 🗑️
  ['\u{1F5D1}', 'close'],       // 🗑
  ['\u{1F3C1}', 'flag'],        // 🏁
  ['\u{1F3E2}', 'building'],    // 🏢
  ['\u{1F465}', 'users'],       // 👥
  ['\u{1F4CC}', 'location'],    // 📌
  ['\u{1F504}', 'refresh'],     // 🔄
  ['\u{1F454}', 'user'],        // 👔
  ['\u{1F4F7}', 'edit'],        // 📷
  ['\u{1F4BE}', 'check'],       // 💾
  ['\u{1F4E7}', 'email'],       // 📧
  ['\u{1F4F1}', 'phone'],       // 📱
  ['\u{1F30D}', 'globe'],       // 🌍
  ['\u{1F4BC}', 'building'],    // 💼
  ['\u{1F511}', 'lock'],        // 🔑
  ['\u{1F6A8}', 'alert'],       // 🚨
  ['\u{26A1}', 'alert'],        // ⚡
  ['\u{1F510}', 'lock'],        // 🔐
  ['\u{1F310}', 'globe'],       // 🌐
  ['\u{1F513}', 'lock'],        // 🔓
  ['\u{1F512}', 'lock'],        // 🔒
  ['\u{274C}', 'close'],        // ❌
  ['\u{2757}', 'alert'],        // ❗
  ['\u{1F389}', 'star'],        // 🎉
  ['\u{1F91D}', 'handshake'],   // 🤝
  ['\u{1F44D}', 'check'],       // 👍
  ['\u{1F44E}', 'close'],       // 👎
  ['\u{1F60E}', 'check'],       // 😎
  ['\u{1F4AF}', 'check'],       // 💯
  ['\u{2728}', 'star'],         // ✨
  ['\u{1F44B}', 'check'],       // 👋
  ['\u{1F44F}', 'check'],       // 👏
  ['\u{1F64F}', 'check'],       // 🙏
  ['\u{1F4AA}', 'check'],       // 💪
  ['\u{1F9E0}', 'check'],       // 🧠
  ['\u{1F440}', 'eye'],         // 👀
  ['\u{2714}\u{FE0F}', 'check'],// ✔️
  ['\u{2714}', 'check'],        // ✔
  ['\u{2716}\u{FE0F}', 'close'],// ✖️
  ['\u{2716}', 'close'],        // ✖
  ['\u{1FAAA}', 'credit-card'], // 🪪
  ['\u{1F515}', 'bell'],        // 🔕
  ['\u{1F31F}', 'star'],        // 🌟
  ['\u{2B55}', 'check'],        // ⭕
  ['\u{1F4B0}', 'check'],       // 💰
  ['\u{1F622}', 'alert'],       // 😢
  ['\u{1F621}', 'alert'],       // 😡
  ['\u{1F60A}', 'check'],       // 😊
  ['\u{1F9C0}', 'check'],       // 🧀
];

let count = 0;

// PASS 1: Replace icon:'EMOJI' or icon:"EMOJI" patterns with icon:'name'
for (const [emoji, name] of replacements) {
  const re1 = new RegExp(`icon:'${escapeRegex(emoji)}'`, 'g');
  const re2 = new RegExp(`icon:"${escapeRegex(emoji)}"`, 'g');
  content = content.replace(re1, () => { count++; return `icon:'${name}'`; });
  content = content.replace(re2, () => { count++; return `icon:"${name}"`; });
}

// PASS 2: Replace standalone EMOJI in JSX with <AppIcon name="name" />
for (const [emoji, name] of replacements) {
  const re = new RegExp(escapeRegex(emoji), 'g');
  content = content.replace(re, () => { count++; return `<AppIcon name="${name}" />`; });
}

// Fix double-wrapped AppIcon: <AppIcon name="<AppIcon name="x" />" /> -> <AppIcon name="x" />
content = content.replace(/<AppIcon name="<AppIcon name="([^"]*)" \/>" \/>/g, '<AppIcon name="$1" />');

// Fix AppIcon inside string literals (labels, etc.) - strip it
content = content.replace(/'\s*<AppIcon name="([^"]*)" \/>/g, "'");
content = content.replace(/<AppIcon name="([^"]*)" \\/>\s*'/g, "'");

fs.writeFileSync(filePath, content, 'utf8');
console.log(`Replaced ${count} emojis in ${filePath}`);

function escapeRegex(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

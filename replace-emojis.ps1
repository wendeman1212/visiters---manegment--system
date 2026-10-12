$filePath = $args[0]
$content = Get-Content $filePath -Raw -Encoding UTF8
$original = $content

# PASS 1: Replace emojis in JSX/HTML context (outside of quotes)
# These patterns appear as standalone JSX content or in template expressions
$jsxMap = [ordered]@{
    [char]0x1F4CD  # 📍
    [char]0x23F3   # ⏳
    [char]0x2705   # ✅
    [char]0x1F4CB  # 📋
    [char]0x1F4C5  # 📅
    [char]0x1F464  # 👤
    [char]0x1F4AC  # 💬
    [char]0x1F4DD  # 📝
    [char]0x1F3AB  # 🎫
    [char]0x2B50   # ⭐
    [char]0x1F50D  # 🔍
    [char]0x1F4CA  # 📊
    [char]0x1F514  # 🔔
    [char]0x26A0   # ⚠  (without VS16)
    [char]0x1F4E5  # 📥
    [char]0x1F4E4  # 📤
    [char]0x1F6AA  # 🚪
    [char]0x1F6E1  # 🛡  (without VS16)
    [char]0x270F   # ✏  (without VS16)
    [char]0x1F5D1  # 🗑  (without VS16)
    [char]0x23F1   # ⏱  (without VS16)
    [char]0x1F3C1  # 🏁
    [char]0x1F3E2  # 🏢
    [char]0x1F465  # 👥
    [char]0x1F4CC  # 📌
    [char]0x1F504  # 🔄
    [char]0x1F454  # 👔
    [char]0x1F4F7  # 📷
    [char]0x1F4BE  # 💾
    [char]0x1F4E7  # 📧
    [char]0x1F4F1  # 📱
    [char]0x1F30D  # 🌍
    [char]0x1F4BC  # 💼
    [char]0x1F511  # 🔑
    [char]0x1F6A8  # 🚨
    [char]0x26A1   # ⚡
    [char]0x1F396  # 🎖  (without VS16)
    [char]0x1F510  # 🔐
    [char]0x1F5A8  # 🖨  (without VS16)
    [char]0x1F310  # 🌐
    [char]0x1F500  # 🔀
    [char]0x1F3F7  # 🏷  (without VS16)
    [char]0x1FAAA  # 🪪
    [char]0x26FF   # ⚿
    [char]0x1F513  # 🔓
    [char]0x1F512  # 🔒
    [char]0x1F6E2  # 🛢  (without VS16)
    [char]0x1F534  # 🔴
    [char]0x1F7E2  # 🟢
    [char]0x1F535  # 🔵
    [char]0x26AA   # ⚪
    [char]0x1F7E1  # 🟡
    [char]0x1F515  # 🔕
    [char]0x1F4E6  # 📦
    [char]0x1F680  # 🚀
    [char]0x1F517  # 🔗
    [char]0x1F4C4  # 📄
    [char]0x1F4C2  # 📂
    [char]0x2714   # ✔
    [char]0x2716   # ✖
    [char]0x1F6D1  # 🛑
    [char]0x1F4A4  # 💤
    [char]0x1F4A5  # 💥
    [char]0x1F60E  # 😎
    [char]0x1F622  # 😢
    [char]0x1F621  # 😡
    [char]0x1F60A  # 😊
    [char]0x1F44D  # 👍
    [char]0x1F44E  # 👎
    [char]0x1F4AF  # 💯
    [char]0x1F389  # 🎉
    [char]0x1F38A  # 🎊
    [char]0x2764   # ❤  (without VS16)
    [char]0x1F9E0  # 🧠
    [char]0x1F440  # 👀
    [char]0x1F44B  # 👋
    [char]0x1F91D  # 🤝
    [char]0x1F44F  # 👏
    [char]0x1F64F  # 🙏
    [char]0x1F4AA  # 💪
    [char]0x1F48E  # 💎
    [char]0x1F48D  # 💍
    [char]0x2728   # ✨
    [char]0x2B55   # ⭕
    [char]0x274C   # ❌
    [char]0x2753   # ❓
    [char]0x2757   # ❗
    [char]0x1F4B0  # 💰
    [char]0x1F4B5  # 💵
    [char]0x1F4B3  # 💳
    [char]0x1F4B8  # 💸
    [char]0x1F4A7  # 💧
    [char]0x1F4A6  # 💦
    [char]0x1F31F  # 🌟
    [char]0x1F308  # 🌈
    [char]0x1F33B  # 🌻
    [char]0x1F339  # 🌹
    [char]0x1F33A  # 🌺
    [char]0x1F337  # 🌷
    [char]0x1F338  # 🌸
    [char]0x1F33C  # 🌼
    [char]0x1F331  # 🌱
    [char]0x1F332  # 🌲
    [char]0x1F333  # 🌳
    [char]0x1F334  # 🌴
    [char]0x1F335  # 🌵
    [char]0x1F33E  # 🌾
    [char]0x1F343  # 🍃
    [char]0x1F342  # 🍂
    [char]0x1F341  # 🍁
    [char]0x1F344  # 🍄
    [char]0x1F345  # 🍅
    [char]0x1F346  # 🍆
    [char]0x1F348  # 🍈
    [char]0x1F349  # 🍉
    [char]0x1F34A  # 🍊
    [char]0x1F34B  # 🍋
    [char]0x1F34C  # 🍌
    [char]0x1F34D  # 🍍
    [char]0x1F34E  # 🍎
    [char]0x1F34F  # 🍏
    [char]0x1F350  # 🍐
    [char]0x1F351  # 🍑
    [char]0x1F352  # 🍒
    [char]0x1F353  # 🍓
    [char]0x1F354  # 🍔
    [char]0x1F355  # 🍕
    [char]0x1F356  # 🍖
    [char]0x1F357  # 🍗
    [char]0x1F358  # 🍘
    [char]0x1F359  # 🍙
    [char]0x1F35A  # 🍚
    [char]0x1F35B  # 🍛
    [char]0x1F35C  # 🍜
    [char]0x1F35D  # 🍝
    [char]0x1F35E  # 🍞
    [char]0x1F35F  # 🍟
    [char]0x1F360  # 🍠
    [char]0x1F361  # 🍡
    [char]0x1F362  # 🍢
    [char]0x1F363  # 🍣
    [char]0x1F364  # 🍤
    [char]0x1F365  # 🍥
    [char]0x1F366  # 🍦
    [char]0x1F367  # 🍧
    [char]0x1F368  # 🍨
    [char]0x1F369  # 🍩
    [char]0x1F36A  # 🍪
    [char]0x1F36B  # 🍫
    [char]0x1F36C  # 🍬
    [char]0x1F36D  # 🍭
    [char]0x1F36E  # 🍮
    [char]0x1F36F  # 🍯
    [char]0x1F370  # 🍰
    [char]0x1F371  # 🍱
    [char]0x1F372  # 🍲
    [char]0x1F373  # 🍳
    [char]0x1F374  # 🍴
    [char]0x1F375  # 🍵
    [char]0x1F376  # 🍶
    [char]0x1F377  # 🍷
    [char]0x1F378  # 🍸
    [char]0x1F379  # 🍹
    [char]0x1F37A  # 🍺
    [char]0x1F37B  # 🍻
    [char]0x1F942  # 🥂
    [char]0x1F943  # 🥃
    [char]0x1F944  # 🥄
    [char]0x1F945  # 🥅
    [char]0x1F946  # 🥆
    [char]0x1F947  # 🥇
    [char]0x1F948  # 🥈
    [char]0x1F949  # 🥉
    [char]0x1F94A  # 🥊
    [char]0x1F94B  # 🥋
    [char]0x1F94C  # 🥌
    [char]0x1F94D  # 🥍
    [char]0x1F94E  # 🥎
    [char]0x1F94F  # 🥏
    [char]0x1F950  # 🥐
    [char]0x1F951  # 🥑
    [char]0x1F952  # 🥒
    [char]0x1F953  # 🥓
    [char]0x1F954  # 🥔
    [char]0x1F955  # 🥕
    [char]0x1F956  # 🥖
    [char]0x1F957  # 🥗
    [char]0x1F958  # 🥘
    [char]0x1F959  # 🥙
    [char]0x1F95A  # 🥚
    [char]0x1F95B  # 🥛
    [char]0x1F95C  # 🥜
    [char]0x1F95D  # 🥝
    [char]0x1F95E  # 🥞
    [char]0x1F95F  # 🥟
    [char]0x1F960  # 🥠
    [char]0x1F961  # 🥡
    [char]0x1F962  # 🥢
    [char]0x1F963  # 🥣
    [char]0x1F964  # 🥤
    [char]0x1F965  # 🥥
    [char]0x1F966  # 🥦
    [char]0x1F967  # 🥧
    [char]0x1F968  # 🥨
    [char]0x1F969  # 🥩
    [char]0x1F96A  # 🥪
    [char]0x1F96B  # 🥫
    [char]0x1F96C  # 🥬
    [char]0x1F96D  # 🥭
    [char]0x1F96E  # 🥮
    [char]0x1F96F  # 🥯
    [char]0x1F970  # 🥰
    [char]0x1F973  # 🥳
    [char]0x1F974  # 🥴
    [char]0x1F975  # 🥵
    [char]0x1F976  # 🥶
    [char]0x1F97A  # 🥺
    [char]0x1F97B  # 🥻
    [char]0x1F97C  # 🥼
    [char]0x1F97D  # 🥽
    [char]0x1F97E  # 🥾
    [char]0x1F97F  # 🥿
    [char]0x1F980  # 🦀
    [char]0x1F981  # 🦁
    [char]0x1F982  # 🦂
    [char]0x1F983  # 🦃
    [char]0x1F984  # 🦄
    [char]0x1F985  # 🦅
    [char]0x1F986  # 🦆
    [char]0x1F987  # 🦇
    [char]0x1F988  # 🦈
    [char]0x1F989  # 🦉
    [char]0x1F98A  # 🦊
    [char]0x1F98B  # 🦋
    [char]0x1F98C  # 🦌
    [char]0x1F98D  # 🦍
    [char]0x1F98E  # 🦎
    [char]0x1F98F  # 🦏
    [char]0x1F990  # 🦐
    [char]0x1F991  # 🦑
    [char]0x1F992  # 🦒
    [char]0x1F993  # 🦓
    [char]0x1F994  # 🦔
    [char]0x1F995  # 🦕
    [char]0x1F996  # 🦖
    [char]0x1F997  # 🦗
    [char]0x1F998  # 🦘
    [char]0x1F999  # 🦙
    [char]0x1F99A  # 🦚
    [char]0x1F99B  # 🦛
    [char]0x1F99C  # 🦜
    [char]0x1F99D  # 🦝
    [char]0x1F99E  # 🦞
    [char]0x1F99F  # 🦟
    [char]0x1F9A0  # 🦠
    [char]0x1F9A1  # 🦡
    [char]0x1F9A2  # 🦢
    [char]0x1F9A3  # 🦣
    [char]0x1F9A4  # 🦤
    [char]0x1F9A5  # 🦥
    [char]0x1F9A6  # 🦦
    [char]0x1F9A7  # 🦧
    [char]0x1F9A8  # 🦨
    [char]0x1F9A9  # 🦩
    [char]0x1F9AA  # 🦪
    [char]0x1F9AB  # 🦫
    [char]0x1F9AC  # 🦬
    [char]0x1F9AD  # 🦭
    [char]0x1F9AE  # 🦮
    [char]0x1F9AF  # 🦯
    [char]0x1F9B0  # 🦰
    [char]0x1F9B1  # 🦱
    [char]0x1F9B2  # 🦲
    [char]0x1F9B3  # 🦳
    [char]0x1F9B4  # 🦴
    [char]0x1F9B5  # 🦵
    [char]0x1F9B6  # 🦶
    [char]0x1F9B7  # 🦷
    [char]0x1F9B8  # 🦸
    [char]0x1F9B9  # 🦹
    [char]0x1F9BA  # 🦺
    [char]0x1F9BB  # 🦻
    [char]0x1F9BC  # 🦼
    [char]0x1F9BD  # 🦽
    [char]0x1F9BE  # 🦾
    [char]0x1F9BF  # 🦿
    [char]0x1F9C0  # 🧀
    [char]0x1F9C1  # 🧁
    [char]0x1F9C2  # 🧂
    [char]0x1F9C3  # 🧃
    [char]0x1F9C4  # 🧄
    [char]0x1F9C5  # 🧅
    [char]0x1F9C6  # 🧆
    [char]0x1F9C7  # 🧇
    [char]0x1F9C8  # 🧈
    [char]0x1F9C9  # 🧉
    [char]0x1F9CA  # 🧊
    [char]0x1F9CB  # 🧋
    [char]0x1F9CC  # 🧌
    [char]0x1F9CD  # 🧍
    [char]0x1F9CE  # 🧎
    [char]0x1F9CF  # 🧏
    [char]0x1F9D0  # 🧐
    [char]0x1F9D1  # 🧑
    [char]0x1F9D2  # 🧒
    [char]0x1F9D3  # 🧓
    [char]0x1F9D4  # 🧔
    [char]0x1F9D5  # 🧕
    [char]0x1F9D6  # 🧖
    [char]0x1F9D7  # 🧗
    [char]0x1F9D8  # 🧘
    [char]0x1F9D9  # 🧙
    [char]0x1F9DA  # 🧚
    [char]0x1F9DB  # 🧛
    [char]0x1F9DC  # 🧜
    [char]0x1F9DD  # 🧝
    [char]0x1F9DE  # 🧞
    [char]0x1F9DF  # 🧟
    [char]0x1F9E0  # 🧠
    [char]0x1F9E1  # 🧡
    [char]0x1F9E2  # 🧢
    [char]0x1F9E3  # 🧣
    [char]0x1F9E4  # 🧤
    [char]0x1F9E5  # 🧥
    [char]0x1F9E6  # 🧦
    [char]0x1F9E7  # 🧧
    [char]0x1F9E8  # 🧨
    [char]0x1F9E9  # 🧩
    [char]0x1F9EA  # 🧪
    [char]0x1F9EB  # 🧫
    [char]0x1F9EC  # 🧬
    [char]0x1F9ED  # 🧭
    [char]0x1F9EE  # 🧮
    [char]0x1F9EF  # 🧯
    [char]0x1F9F0  # 🧰
    [char]0x1F9F1  # 🧱
    [char]0x1F9F2  # 🧲
    [char]0x1F9F3  # 🧳
    [char]0x1F9F4  # 🧴
    [char]0x1F9F5  # 🧵
    [char]0x1F9F6  # 🧶
    [char]0x1F9F7  # 🧷
    [char]0x1F9F8  # 🧸
    [char]0x1F9F9  # 🧹
    [char]0x1F9FA  # 🧺
    [char]0x1F9FB  # 🧻
    [char]0x1F9FC  # 🧼
    [char]0x1F9FD  # 🧽
    [char]0x1F9FE  # 🧾
    [char]0x1F9FF  # 🧿
}

# This is too complex for simple replacement. Use a simpler approach:
# Just find and replace common emojis with specific replacements

# Simple mapping: emoji -> replacement text (for inside strings) or AppIcon (for JSX)
# We'll handle the two contexts separately

# First: replace emojis that ONLY appear in JSX (never in strings)
# These are safe to replace directly
$safeReplacements = @{
    ([char]0x23F3).ToString()  = '<AppIcon name="clock" />'    # ⏳
    ([char]0x2705).ToString()  = '<AppIcon name="check" />'    # ✅
    ([char]0x1F4CB).ToString() = '<AppIcon name="clipboard" />'# 📋
    ([char]0x1F4C5).ToString() = '<AppIcon name="calendar" />' # 📅
    ([char]0x1F464).ToString() = '<AppIcon name="user" />'     # 👤
    ([char]0x1F4AC).ToString() = '<AppIcon name="message" />'  # 💬
    ([char]0x1F4DD).ToString() = '<AppIcon name="edit" />'     # 📝
    ([char]0x1F3AB).ToString() = '<AppIcon name="credit-card" />'# 🎫
    ([char]0x2B50).ToString()  = '<AppIcon name="star" />'     # ⭐
    ([char]0x1F50D).ToString() = '<AppIcon name="search" />'   # 🔍
    ([char]0x1F4CA).ToString() = '<AppIcon name="chart" />'    # 📊
    ([char]0x1F514).ToString() = '<AppIcon name="bell" />'     # 🔔
    ([char]0x1F4E5).ToString() = '<AppIcon name="download" />' # 📥
    ([char]0x1F4E4).ToString() = '<AppIcon name="send" />'     # 📤
    ([char]0x1F6AA).ToString() = '<AppIcon name="exit" />'     # 🚪
    ([char]0x1F4CC).ToString() = '<AppIcon name="location" />' # 📌
    ([char]0x1F504).ToString() = '<AppIcon name="refresh" />'  # 🔄
    ([char]0x1F454).ToString() = '<AppIcon name="user" />'     # 👔
    ([char]0x1F4F7).ToString() = '<AppIcon name="edit" />'     # 📷
    ([char]0x1F4BE).ToString() = '<AppIcon name="check" />'    # 💾
    ([char]0x1F4E7).ToString() = '<AppIcon name="email" />'    # 📧
    ([char]0x1F4F1).ToString() = '<AppIcon name="phone" />'    # 📱
    ([char]0x1F30D).ToString() = '<AppIcon name="globe" />'    # 🌍
    ([char]0x1F4BC).ToString() = '<AppIcon name="building" />' # 💼
    ([char]0x1F511).ToString() = '<AppIcon name="lock" />'     # 🔑
    ([char]0x1F6A8).ToString() = '<AppIcon name="alert" />'    # 🚨
    ([char]0x26A1).ToString()  = '<AppIcon name="alert" />'    # ⚡
    ([char]0x1F510).ToString() = '<AppIcon name="lock" />'     # 🔐
    ([char]0x1F310).ToString() = '<AppIcon name="globe" />'    # 🌐
    ([char]0x1F500).ToString() = '<AppIcon name="refresh" />'  # 🔀
    ([char]0x1F513).ToString() = '<AppIcon name="lock" />'     # 🔓
    ([char]0x1F512).ToString() = '<AppIcon name="lock" />'     # 🔒
    ([char]0x1F44D).ToString() = '<AppIcon name="check" />'    # 👍
    ([char]0x1F44E).ToString() = '<AppIcon name="close" />'    # 👎
    ([char]0x274C).ToString()  = '<AppIcon name="close" />'    # ❌
    ([char]0x1F3E2).ToString() = '<AppIcon name="building" />' # 🏢
    ([char]0x1F465).ToString() = '<AppIcon name="users" />'    # 👥
    ([char]0x2728).ToString()  = '<AppIcon name="star" />'     # ✨
    ([char]0x2714).ToString()  = '<AppIcon name="check" />'    # ✔
    ([char]0x2716).ToString()  = '<AppIcon name="close" />'    # ✖
    ([char]0x2757).ToString()  = '<AppIcon name="alert" />'    # ❗
    ([char]0x2753).ToString()  = '<AppIcon name="search" />'   # ❓
    ([char]0x1F4CD).ToString() = '<AppIcon name="location" />' # 📍
    ([char]0x23F1).ToString()  = '<AppIcon name="clock" />'    # ⏱
    ([char]0x1F3C1).ToString() = '<AppIcon name="flag" />'     # 🏁
    ([char]0x1F389).ToString() = '<AppIcon name="star" />'     # 🎉
    ([char]0x1F38A).ToString() = '<AppIcon name="star" />'     # 🎊
    ([char]0x1F44B).ToString() = '<AppIcon name="check" />'    # 👋
    ([char]0x1F91D).ToString() = '<AppIcon name="handshake" />'# 🤝
    ([char]0x1F44F).ToString() = '<AppIcon name="check" />'    # 👏
    ([char]0x1F64F).ToString() = '<AppIcon name="check" />'    # 🙏
    ([char]0x1F4AA).ToString() = '<AppIcon name="check" />'    # 💪
    ([char]0x1F4B0).ToString() = '<AppIcon name="check" />'    # 💰
    ([char]0x1F31F).ToString() = '<AppIcon name="star" />'     # 🌟
    ([char]0x1F60E).ToString() = '<AppIcon name="check" />'    # 😎
    ([char]0x1F622).ToString() = '<AppIcon name="alert" />'    # 😢
    ([char]0x1F621).ToString() = '<AppIcon name="alert" />'    # 😡
    ([char]0x1F60A).ToString() = '<AppIcon name="check" />'    # 😊
    ([char]0x1F4AF).ToString() = '<AppIcon name="check" />'    # 💯
    ([char]0x1F9E0).ToString() = '<AppIcon name="check" />'    # 🧠
    ([char]0x1F440).ToString() = '<AppIcon name="eye" />'      # 👀
    ([char]0x1F9C0).ToString() = '<AppIcon name="check" />'    # 🧀
}

$count = 0
foreach ($emoji in $safeReplacements.Keys) {
    $before = $content.Length
    $content = $content.Replace($emoji, $safeReplacements[$emoji])
    # track nothing, just do it
}

[System.IO.File]::WriteAllText($filePath, $content, [System.Text.UTF8Encoding]::new($false))
Write-Host "Done: $filePath"

# Riot Comments

**A better comment box for WordPress.**

Readers start with **“What’s on your mind?”** and an empty box. They can write right away, add a name and private email when the site requires them, and see their comment or reply appear in place. Editors keep the WordPress moderation tools they already use.

**WordPress 6.3+** · **PHP 7.4+** · **Vanilla JavaScript** · **MIT**

## The reader experience

- **Write first.** Riot opens the name and email fields inline at submission. Signed-in readers and guests who chose to save their details can post directly.
- **Stay in the thread.** Reply in place, switch between newest and oldest, or load more comments without reloading the article.
- **See what happened.** A published comment appears immediately and updates the public count. A pending comment gets a private **Awaiting approval** preview. Rejections leave the draft in the composer.
- **Never lose a comment to a hiccup.** If the connection or the server fails, Riot retries once on its own. Each draft carries a one-time key, so a retry can't post the same comment twice.
- **Find the discussion fast.** An optional floating **Comments** button follows readers down the article and drops them straight into the composer.
- **Small comforts.** The composer grows as you type, **Ctrl/⌘ + Enter** posts, recent comments show times like "5m ago", and every comment has a **Copy link** action.
- **Post with JavaScript disabled.** The form, reply links, sorting, and pagination work through standard navigation.

## The look

Riot borrows your theme's colors and fonts when it can (it reads common variables like `--red`, `--ink`, `--line`, and the block theme's primary color). The default style is editorial: square corners, a small accent eyebrow label, a bold heading, an accent call-to-action, colored initials, an **Author** badge, and status-colored feedback. In **Settings → Riot Comments** you can switch to rounded corners, change or hide the eyebrow label, set an accent color, pick light, dark, or theme appearance, and turn the floating button off.

## What readers see

| WordPress outcome | On the article |
| --- | --- |
| Published | **Comment posted!** The comment joins the public discussion. |
| Held by the site's moderation rules | **Awaiting approval.** The author sees a private preview while it’s in review. |
| Disposable email held for review | **Email address flagged.** The author sees the reason and a private preview. |
| Disposable email rejected | **Email address flagged.** The draft stays put and the email field is ready to edit. |
| Spam blocked | **Comment could not be published.** The draft stays available to the reader. |
| Server or plugin interrupted the request | **Not posted yet.** Riot retries once, then explains what happened and keeps the draft. |

## Install

Clone the repository and build a WordPress-ready ZIP from the tracked files.

```sh
git clone https://github.com/riotrequest/riot-comments.git
cd riot-comments
git archive --format=zip --prefix=riot-comments/ -o ../riot-comments.zip HEAD
```

1. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and choose the ZIP.
2. Activate **Riot Comments**, then open **Settings → Riot Comments** for appearance and screening options.
3. Purge your site's page cache and open an article with comments enabled.

Riot works with standard classic and block comment templates. Use `[riot_comments]` on a post or page when the theme omits the comment area. To return to the theme's original comment form, disable **Use Riot Comments** in settings and purge the page cache. Comments and moderation history stay in WordPress.

## Email screening and privacy

Riot sends the email domain to **DeBounce** for a disposable-domain check and uses **Disify** as a fallback. If both are unavailable, the site’s usual moderation rules apply. Disposable domains go to moderation by default. Editors can choose rejection instead. A clean domain verdict does not verify inbox ownership.

Email addresses stay private. Guests can opt in to WordPress commenter cookies to reuse their details. A separate seven-day browser cookie lets guest authors revisit their own pending comments. It stores a random identifier.

## Troubleshoot a submission

Riot answers every submission itself. If another plugin, a PHP error, or the host interrupts one (a `wp_die()`, an `exit`, an exception, a redirect, or a host error page), the reader sees a clear **Not posted yet** message and Riot records what happened under **Settings → Riot Comments → Submission problems**: the time, the stage (validation, spam checks, screening, insert), the HTTP status, and the error or output that ended the request. The log stores no comment text, names, or email addresses.

If a comment is blocked as spam, Riot is reporting your spam plugin's decision. With Antispam Bee, the reason is stored on the comment (`antispam_bee_reason`). Note that its local spam-database check also matches the author's website and IP, so a test comment from your own account can teach it to block you.

## License

MIT. See [LICENSE](LICENSE).

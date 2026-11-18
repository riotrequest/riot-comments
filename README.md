# Riot Comments

**A better comment box for WordPress.**

Readers start with **“What’s on your mind?”** and an empty box. They can write right away, add a name and private email when the site requires them, and see their comment or reply appear in place. Editors keep the WordPress moderation tools they already use.

**WordPress 6.3+** · **PHP 7.4+** · **Vanilla JavaScript** · **GPL-2.0-or-later**

## The reader experience

- **Write first.** Riot opens the name and email fields inline at submission. Signed-in readers and guests who chose to save their details can post directly.
- **Stay in the thread.** Reply in place, switch between newest and oldest, or load more comments without reloading the article.
- **See what happened.** A published comment appears immediately and updates the public count. A pending comment gets a private **Awaiting approval** preview. Rejections leave the draft in the composer.
- **Post with JavaScript disabled.** The form, reply links, sorting, and pagination work through standard navigation.

## What readers see

| WordPress outcome | On the article |
| --- | --- |
| Published | **Comment posted!** The comment joins the public discussion. |
| Held by the site's moderation rules | **Awaiting approval.** The author sees a private preview while it’s in review. |
| Disposable email held for review | **Email address flagged.** The author sees the reason and a private preview. |
| Disposable email rejected | **Email address flagged.** The draft stays put and the email field is ready to edit. |
| Spam blocked | **Comment could not be published.** The draft stays available to the reader. |

## Install

Clone the repository and build a WordPress-ready ZIP from the tracked files.

```sh
git clone https://github.com/riotrequest/riot-comments.git
cd riot-comments
git archive --format=zip --prefix=riot-comments/ -o ../riot-comments-1.2.0.zip HEAD
```

1. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and choose the ZIP.
2. Activate **Riot Comments**, then open **Settings → Riot Comments** for appearance and screening options.
3. Purge your site's page cache and open an article with comments enabled.

Riot works with standard classic and block comment templates. Use `[riot_comments]` on a post or page when the theme omits the comment area. To return to the theme's original comment form, disable **Use Riot Comments** in settings and purge the page cache. Comments and moderation history stay in WordPress.

## Email screening and privacy

Riot sends the email domain to **DeBounce** for a disposable-domain check and uses **Disify** as a fallback. If both are unavailable, the site’s usual moderation rules apply. Disposable domains go to moderation by default. Editors can choose rejection instead. A clean domain verdict does not verify inbox ownership.

Email addresses stay private. Guests can opt in to WordPress commenter cookies to reuse their details. A separate seven-day browser cookie lets guest authors revisit their own pending comments. It stores a random identifier.

## Troubleshoot a submission

For an unexpected submission error, find the request to `wp-comments-post.php` in the browser’s Network tab. Record the HTTP status, content type, and any redirect, then match the timestamp in the origin access log and check **Comments** in WordPress. Remove addresses, moderation tokens, and cookies before sharing a trace.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

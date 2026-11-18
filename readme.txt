=== Riot Comments ===
Requires at least: 6.3
Requires PHP: 7.4
Stable tag: 1.3.0
License: MIT
License URI: https://opensource.org/license/mit

Guest-first discussions, native WordPress moderation, and optional disposable-domain screening.

== Installation ==

1. Open Plugins > Add New Plugin > Upload Plugin in WordPress and upload riot-comments-1.3.0.zip.
2. Activate Riot Comments, then open Settings > Riot Comments. The replacement and disposable-domain screening are enabled by default.
3. Purge any full-page cache and open a published article with comments enabled.

Classic themes using comments_template() and block themes using the core Comments blocks are supported. Add [riot_comments] on singular posts or pages when the theme omits the comments area. WordPress still controls which posts are open for comments.

To restore the theme's comment interface, turn off Use Riot Comments in Settings > Riot Comments or deactivate the plugin, then purge the page cache. Upgrade through Plugins > Add New Plugin > Upload Plugin and choose Replace current with uploaded. Comments remain in WordPress and site settings are retained.

== Reader experience ==

Readers can start typing immediately. A new guest sees the display name and private email fields after pressing Post. The fields follow the site's WordPress Discussion settings. The optional Remember me checkbox enables WordPress's native commenter cookies. Returning guests can post immediately or use Edit name or email to update the details with their next post. Signed-in readers post using their WordPress account. Without JavaScript, name and email appear up front and posting, replies, ordering, and additional pages use standard navigation.

Draft comment text is saved per discussion and reply in the current browser tab. An ordinary submission failure keeps the text and entered details. Draft text and entered details are also preserved on the plugin's non-JavaScript retry page. Third-party plugins that end an HTTP request directly can show their own response.

== Moderation and screening ==

Riot Comments follows WordPress's login requirement and runs its usual duplicate, flood, spam, and moderation checks. Approved comments and replies use the standard Comments screen. The public count includes approved comments only.

Comments awaiting approval appear immediately to their author. Signed-in authors can see their own pending previews through their WordPress account. Guest authors use a separate, randomly generated, HttpOnly browser cookie that expires after seven days. It contains no name or email and is created only when a guest posts a pending comment. Pending previews are returned from a private, non-cacheable request. The JavaScript-disabled pending receipt also uses a private, non-cacheable response. Previews apply to comments submitted with Riot Comments 1.1.0 or later.

Riot Comments checks the email format locally and sends only the normalized domain to DeBounce or Disify. It uses Disify only when DeBounce fails or cannot return a recognizable verdict. Successful verdicts are cached for 24 hours and outages for one minute. A known privacy relay is allowed without a lookup. If both providers fail, the verdict is recorded as unknown and the normal moderation checks apply. A disposable result goes to moderation by default. A clean result is a domain screening result and does not establish access to the inbox.

The Settings screen shows each provider's latest result and last error. Clear domain verdict cache invalidates existing verdicts immediately. Older entries expire naturally. Instances keep their own settings and cache.

== Changelog ==

= 1.3.0 =
* Every submission now ends in a clear, structured answer. If another plugin, a PHP error, or the host interrupts a submission, readers see what happened, their draft is kept, and the cause is recorded under Settings > Riot Comments > Submission problems.
* A failed connection or server error is retried once automatically. Each draft carries a one-time key, so a retry can never post the same comment twice.
* Private previews and screening results are recorded the moment WordPress stores the comment.
* New editorial look: eyebrow label, count chip, accent call-to-action, colored initials, Author badge, segmented sorting, status-colored feedback, and relative timestamps.
* Copy-link action on each comment, Ctrl/Cmd + Enter to post, and a composer that grows as you type.
* Optional floating Comments button that jumps readers to the discussion.
* New settings: Corners (square or rounded), Eyebrow label, and Jump button.
* Relicensed under the MIT License.

= 1.2.0 =
* Show accessible inline feedback for publication, moderation, disposable email holds and rejections, and spam blocks.
* Keep rejected drafts editable and prioritize the actual WordPress outcome when spam protection blocks submission.

= 1.1.0 =
* Explicitly resolve guest identity, with an edit control for saved details.
* Show approved and privately held comments and replies immediately, with an approved-only count.
* Refresh the discussion and private previews after reload and preserve WordPress Discussion requirements.

= 1.0.0 =
* Initial release.

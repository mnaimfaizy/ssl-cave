<?php /** @var string $appName */ ?>
<div class="login-wrap">
    <article>
        <h1><?= e($appName) ?></h1>
        <p class="muted">SSL inventory for this hosting account.</p>
        <form method="post" action="/?r=login">
            <?= Csrf::field() ?>
            <label>
                Username
                <input type="text" name="username" autocomplete="username" required>
            </label>
            <label>
                Password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit">Sign in</button>
        </form>
    </article>
</div>

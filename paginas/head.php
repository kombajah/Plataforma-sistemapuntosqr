<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="<?= h(img_url('icono')) ?>">
<meta name="theme-color" content="<?= h(cfg('color_primario', '#6ea36f')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@500;600;700&display=swap" rel="stylesheet">
<style>
<?= css_tema($GLOBALS['CFG']) ?>

body{background:var(--forest-bg);color:var(--forest-text);font-family:'Quicksand',system-ui,-apple-system,Segoe UI,sans-serif}
.header-app{background:linear-gradient(135deg,var(--forest-primary-light),var(--forest-primary));color:var(--on-primary);padding:15px;border-radius:0 0 22px 22px;box-shadow:0 3px 10px #0001}
.header-app a{color:var(--on-primary)!important}
.bg-light{background:var(--forest-bg)!important}
.card{border:1px solid var(--forest-line);border-radius:16px}
.text-primary{color:var(--forest-primary-dark)!important}
.btn-primary{background:var(--forest-primary)!important;border-color:var(--forest-primary)!important;color:var(--on-primary)!important}
.btn-primary:hover{background:var(--forest-primary-dark)!important;border-color:var(--forest-primary-dark)!important;color:#fff!important}
.btn-success{background:var(--forest-primary-dark)!important;border-color:var(--forest-primary-dark)!important;color:#fff!important}
.btn-outline-primary{color:var(--forest-primary-dark)!important;border-color:var(--forest-primary)!important}
.btn-outline-primary:hover{background:var(--forest-primary)!important;color:var(--on-primary)!important}
.badge.bg-primary{background:var(--forest-primary)!important;color:var(--on-primary)!important}
.badge.bg-secondary,.badge.bg-primary-subtle{background:var(--forest-accent)!important;color:var(--forest-text)!important}
.badge.text-primary{color:var(--forest-primary-dark)!important}
.btn-punto{flex:1;margin:0 5px;background:var(--forest-tint);border:none;padding:10px;border-radius:10px;color:var(--forest-text)}
.btn-punto.active{background:var(--forest-primary);color:var(--on-primary)}
.login-card{border-radius:26px;border:none;box-shadow:0 12px 30px #0002;max-width:380px;margin:0 auto}
.login-avatar{width:110px;height:110px;border-radius:50%;object-fit:contain;background:#fff;
  border:5px solid #fff;box-shadow:0 0 0 3px var(--forest-accent),0 6px 16px #0002;padding:8px}
.login-title{color:var(--forest-primary-dark);font-weight:700}
.login-subtitle{color:var(--forest-warn);letter-spacing:1px;font-weight:700;font-size:.8rem;text-transform:uppercase}
.login-btn{background:linear-gradient(135deg,var(--forest-primary-light),var(--forest-primary-dark));border:none;font-weight:700;color:#fff}
.app-footer{max-width:900px;margin:28px auto 14px;padding:14px;text-align:center;font-size:13px;color:var(--forest-muted)}
.app-footer a{color:var(--forest-primary-dark);text-decoration:none;margin:0 8px;font-weight:700}
.app-footer a:hover{text-decoration:underline}
</style>

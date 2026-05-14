package com.reclaim.mobile.screens.activities

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.reclaim.mobile.R
import com.reclaim.mobile.storage.session.SessionManager
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class SplashActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_splash)

        lifecycleScope.launch {
            delay(500)
            val destination = if (SessionManager(this@SplashActivity).isLoggedIn()) {
                MainActivity::class.java
            } else {
                AuthActivity::class.java
            }

            startActivity(Intent(this@SplashActivity, destination))
            finish()
        }
    }
}

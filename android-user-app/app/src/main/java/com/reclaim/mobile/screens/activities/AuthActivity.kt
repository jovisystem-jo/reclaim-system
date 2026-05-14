package com.reclaim.mobile.screens.activities

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import com.google.android.material.button.MaterialButtonToggleGroup
import com.reclaim.mobile.R
import com.reclaim.mobile.screens.fragments.LoginFragment
import com.reclaim.mobile.screens.fragments.RegisterFragment

class AuthActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_auth)

        val toggleGroup: MaterialButtonToggleGroup = findViewById(R.id.authToggleGroup)
        val showLogin: MaterialButton = findViewById(R.id.buttonShowLogin)
        val showRegister: MaterialButton = findViewById(R.id.buttonShowRegister)

        if (savedInstanceState == null) {
            toggleGroup.check(R.id.buttonShowLogin)
            supportFragmentManager.beginTransaction()
                .replace(R.id.authFragmentContainer, LoginFragment())
                .commit()
        }

        showLogin.setOnClickListener {
            toggleGroup.check(R.id.buttonShowLogin)
            supportFragmentManager.beginTransaction()
                .replace(R.id.authFragmentContainer, LoginFragment())
                .commit()
        }

        showRegister.setOnClickListener {
            toggleGroup.check(R.id.buttonShowRegister)
            supportFragmentManager.beginTransaction()
                .replace(R.id.authFragmentContainer, RegisterFragment())
                .commit()
        }
    }
}

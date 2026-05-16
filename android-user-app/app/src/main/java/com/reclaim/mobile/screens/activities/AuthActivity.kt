package com.reclaim.mobile.screens.activities

import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import com.google.android.material.button.MaterialButtonToggleGroup
import com.google.android.material.textfield.TextInputEditText
import com.google.android.material.textfield.TextInputLayout
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiClient
import com.reclaim.mobile.screens.fragments.LoginFragment
import com.reclaim.mobile.screens.fragments.RegisterFragment
import com.reclaim.mobile.storage.settings.AppSettingsManager

class AuthActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_auth)

        val settingsManager = AppSettingsManager(this)
        val toggleGroup: MaterialButtonToggleGroup = findViewById(R.id.authToggleGroup)
        val showLogin: MaterialButton = findViewById(R.id.buttonShowLogin)
        val showRegister: MaterialButton = findViewById(R.id.buttonShowRegister)
        val serverUrlLayout: TextInputLayout = findViewById(R.id.inputServerUrl)
        val serverUrlInput: TextInputEditText = findViewById(R.id.editServerUrl)
        val saveServerUrlButton: MaterialButton = findViewById(R.id.buttonSaveServerUrl)

        serverUrlInput.setText(settingsManager.getApiBaseUrl())

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

        saveServerUrlButton.setOnClickListener {
            serverUrlLayout.error = null

            runCatching {
                settingsManager.saveApiBaseUrl(serverUrlInput.text?.toString().orEmpty())
            }.onSuccess { normalizedUrl ->
                serverUrlInput.setText(normalizedUrl)
                ApiClient.reset()
                Toast.makeText(this, getString(R.string.server_url_saved), Toast.LENGTH_LONG).show()
            }.onFailure {
                serverUrlLayout.error = getString(R.string.server_url_required)
            }
        }
    }
}

package com.reclaim.mobile.screens.fragments

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ProgressBar
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.auth.AuthRepository
import com.reclaim.mobile.screens.activities.MainActivity
import kotlinx.coroutines.launch

class LoginFragment : Fragment() {
    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        return inflater.inflate(R.layout.fragment_login, container, false)
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val email: TextInputEditText = view.findViewById(R.id.editLoginEmail)
        val password: TextInputEditText = view.findViewById(R.id.editLoginPassword)
        val errorText: TextView = view.findViewById(R.id.textLoginError)
        val progress: ProgressBar = view.findViewById(R.id.progressLogin)
        val loginButton: MaterialButton = view.findViewById(R.id.buttonLogin)
        val repository = AuthRepository(requireContext())

        loginButton.setOnClickListener {
            errorText.visibility = View.GONE
            progress.visibility = View.VISIBLE
            loginButton.isEnabled = false

            lifecycleScope.launch {
                repository.login(
                    email.text?.toString()?.trim().orEmpty(),
                    password.text?.toString().orEmpty()
                ).onSuccess {
                    startActivity(Intent(requireContext(), MainActivity::class.java))
                    requireActivity().finish()
                }.onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }

                progress.visibility = View.GONE
                loginButton.isEnabled = true
            }
        }
    }
}

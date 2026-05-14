package com.reclaim.mobile.screens.fragments

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.ProgressBar
import android.widget.Spinner
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.auth.AuthRepository
import com.reclaim.mobile.screens.activities.MainActivity
import kotlinx.coroutines.launch

class RegisterFragment : Fragment() {
    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        return inflater.inflate(R.layout.fragment_register, container, false)
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val name: TextInputEditText = view.findViewById(R.id.editRegisterName)
        val username: TextInputEditText = view.findViewById(R.id.editRegisterUsername)
        val email: TextInputEditText = view.findViewById(R.id.editRegisterEmail)
        val phone: TextInputEditText = view.findViewById(R.id.editRegisterPhone)
        val department: Spinner = view.findViewById(R.id.spinnerRegisterDepartment)
        val studentId: TextInputEditText = view.findViewById(R.id.editRegisterStudentId)
        val password: TextInputEditText = view.findViewById(R.id.editRegisterPassword)
        val confirmPassword: TextInputEditText = view.findViewById(R.id.editRegisterConfirmPassword)
        val errorText: TextView = view.findViewById(R.id.textRegisterError)
        val progress: ProgressBar = view.findViewById(R.id.progressRegister)
        val registerButton: MaterialButton = view.findViewById(R.id.buttonRegister)

        val authRepository = AuthRepository(requireContext())
        val repository = MobileRepository(requireContext())

        lifecycleScope.launch {
            repository.options()
                .onSuccess { options ->
                    department.adapter = ArrayAdapter(requireContext(), android.R.layout.simple_spinner_dropdown_item, options.departments)
                }
                .onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }
        }

        registerButton.setOnClickListener {
            errorText.visibility = View.GONE
            progress.visibility = View.VISIBLE
            registerButton.isEnabled = false

            lifecycleScope.launch {
                authRepository.register(
                    name = name.text?.toString()?.trim().orEmpty(),
                    email = email.text?.toString()?.trim().orEmpty(),
                    username = username.text?.toString()?.trim().orEmpty(),
                    password = password.text?.toString().orEmpty(),
                    confirmPassword = confirmPassword.text?.toString().orEmpty(),
                    studentStaffId = studentId.text?.toString()?.trim().orEmpty(),
                    department = department.selectedItem?.toString().orEmpty(),
                    phone = phone.text?.toString()?.trim().orEmpty()
                ).onSuccess {
                    startActivity(Intent(requireContext(), MainActivity::class.java))
                    requireActivity().finish()
                }.onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }

                progress.visibility = View.GONE
                registerButton.isEnabled = true
            }
        }
    }
}
